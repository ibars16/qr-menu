<?php

namespace App\Controller\Admin;

use App\Attribute\RequiresAi;
use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use App\Service\AiAccess;
use App\Service\BatchProcessingTriggerInterface;
use App\Service\DishPhoto\AiPhotoBatch;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Service\MenuImportLanguageNotice;
use App\Service\MenuImportPdfError;
use App\Service\MenuImportPdfException;
use App\Service\MenuImportPdfSplitter;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadValidationError;
use App\Service\Upload\UploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Upload photos, store them, and kick off the full pipeline automatically —
 * see BatchProcessingTriggerInterface for how "automatically" is implemented
 * today (a spawned background process, since no Messenger worker exists in
 * this deployment yet). The status page (show()) polls statusJson() while
 * processing runs, then the browser is sent to complete() once the batch
 * reaches a terminal state.
 */
#[Route('/admin/menu/import', name: 'admin_menu_import_')]
#[IsGranted('ROLE_STAFF')]
class MenuImportController extends AbstractController
{
    /** Photos + PDF pages together, per upload. */
    private const MAX_PAGES_PER_UPLOAD = 30;

    /** Names listed in the summary's "platos repetidos" line; the rest is "… y N más". */
    private const DUPLICATE_NAMES_SHOWN = 5;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly BatchProcessingTriggerInterface $processingTrigger,
        private readonly UploadValidator $uploadValidator,
        private readonly MenuImportPdfSplitter $pdfSplitter,
    ) {}

    private function restaurant(): Restaurant
    {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException('No restaurant linked to this user.');
        }
        return $restaurant;
    }

    #[Route('', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('admin/menu_import_upload.html.twig', [
            'restaurant' => $this->restaurant(),
        ]);
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    #[RequiresAi(AiAccess::IMPORT, redirectRoute: 'admin_menu_import_new')]
    public function upload(Request $request, EntityManagerInterface $em): Response
    {
        $restaurant = $this->restaurant();

        /** @var UploadedFile[] $files */
        $files = $request->files->all('images') ?? [];

        if (empty($files)) {
            // A body over post_max_size makes PHP drop $_FILES entirely, so
            // "nothing arrived" would read as "nothing was picked" —
            // Content-Length vs. the real ini limit tells the two apart.
            $postMaxSize = self::iniBytes((string) ini_get('post_max_size'));
            $contentLength = (int) $request->server->get('CONTENT_LENGTH', 0);
            $key = $postMaxSize > 0 && $contentLength > $postMaxSize
                ? 'upload.error.total_too_large'
                : 'upload.error.no_files';
            $this->addFlash('error', $this->translator->trans($key, domain: 'admin_menu_import'));
            return $this->redirectToRoute('admin_menu_import_new');
        }

        if (count($files) > self::MAX_PAGES_PER_UPLOAD) {
            return $this->uploadError('upload.error.too_many_files', ['%max%' => self::MAX_PAGES_PER_UPLOAD]);
        }

        // 1) Validate every file and count its pages — nothing is rendered,
        //    stored or created yet. A PDF is recognised by its name OR its
        //    real content, and must then really be a PDF (sniffed bytes).
        $entries = [];
        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            if ($this->looksLikePdf($file)) {
                $result = $this->uploadValidator->validate($file, UploadProfile::MenuImportPdf);
                if (!$result->isValid) {
                    $key = $result->error === UploadValidationError::UnsupportedType ? 'upload.error.pdf_unreadable' : $this->importErrorKey($result->error);
                    return $this->uploadError($key, ['%name%' => $name]);
                }
                try {
                    $pages = $this->pdfSplitter->countPages($file->getPathname());
                } catch (MenuImportPdfException $e) {
                    return $this->uploadError($this->pdfErrorKey($e->error), ['%name%' => $name]);
                }
                $entries[] = ['file' => $file, 'pdf' => true, 'pages' => $pages];
            } else {
                $result = $this->uploadValidator->validate($file, UploadProfile::MenuImportPage);
                if (!$result->isValid) {
                    return $this->uploadError($this->importErrorKey($result->error), ['%name%' => $name]);
                }
                $entries[] = ['file' => $file, 'pdf' => false, 'pages' => 1, 'result' => $result];
            }
        }

        // PDF pages count towards the same 30-page cap as photos.
        $totalPages = array_sum(array_column($entries, 'pages'));
        if ($totalPages > self::MAX_PAGES_PER_UPLOAD) {
            $biggestPdf = null;
            foreach ($entries as $entry) {
                if ($entry['pdf'] && ($biggestPdf === null || $entry['pages'] > $biggestPdf['pages'])) {
                    $biggestPdf = $entry;
                }
            }
            $room = $biggestPdf ? self::MAX_PAGES_PER_UPLOAD - ($totalPages - $biggestPdf['pages']) : 0;
            if ($biggestPdf && $room >= 1) {
                return $this->uploadError('upload.error.pdf_too_many_pages', [
                    '%name%' => $biggestPdf['file']->getClientOriginalName(),
                    '%pages%' => $biggestPdf['pages'],
                    '%max%' => $room,
                ]);
            }
            return $this->uploadError('upload.error.too_many_files', ['%max%' => self::MAX_PAGES_PER_UPLOAD]);
        }

        // 2) Turn each PDF into one JPEG per page (in a temp folder, always
        //    removed), each validated exactly like a photo. Still nothing stored.
        $workDir = sys_get_temp_dir() . '/menu-import-pdf-' . bin2hex(random_bytes(8));
        mkdir($workDir, 0700);
        try {
            /** @var list<array{0: UploadedFile, 1: string, 2: string}> $pagesToStore [file, safe filename, sha256] */
            $pagesToStore = [];
            foreach ($entries as $entry) {
                $file = $entry['file'];
                if (!$entry['pdf']) {
                    $pagesToStore[] = [$file, $entry['result']->safeFilename, hash_file('sha256', $file->getPathname())];
                    continue;
                }

                $pdfDir = $workDir . '/' . count($pagesToStore);
                mkdir($pdfDir, 0700);
                try {
                    $jpegs = $this->pdfSplitter->renderPages($file->getPathname(), $entry['pages'], $pdfDir);
                } catch (MenuImportPdfException $e) {
                    return $this->uploadError($this->pdfErrorKey($e->error), ['%name%' => $file->getClientOriginalName()]);
                }
                foreach ($jpegs as $jpeg) {
                    // A local file wrapped as an UploadedFile ("test" mode:
                    // not from this HTTP request) so it goes through the very
                    // same validator (real type, size, moderation) as a photo.
                    $page = new UploadedFile($jpeg, $file->getClientOriginalName(), 'image/jpeg', null, true);
                    $result = $this->uploadValidator->validate($page, UploadProfile::MenuImportPage);
                    if (!$result->isValid) {
                        return $this->uploadError('upload.error.pdf_unreadable', ['%name%' => $file->getClientOriginalName()]);
                    }
                    $pagesToStore[] = [$page, $result->safeFilename, hash_file('sha256', $jpeg)];
                }
            }

            // 3) Store: all or nothing. Any failure rolls the batch and its
            //    pages back and deletes every file already moved.
            return $this->storeBatch($restaurant, $pagesToStore, $em);
        } finally {
            self::removeDir($workDir);
        }
    }

    /**
     * @param list<array{0: UploadedFile, 1: string, 2: string}> $pagesToStore
     */
    private function storeBatch(Restaurant $restaurant, array $pagesToStore, EntityManagerInterface $em): Response
    {
        $connection = $em->getConnection();
        $connection->beginTransaction();
        $batch = new MenuImportBatch($restaurant);
        $pages = [];
        $movedPaths = [];
        $uploadDir = null;
        $createdDir = false;

        try {
            $em->persist($batch);
            $em->flush(); // assign an id — used in the storage path below

            $uploadDir = $this->getParameter('app.uploads_dir')
                . '/menu-imports/' . $restaurant->getId() . '/' . $batch->getId();
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    throw new FileException('Could not create ' . $uploadDir);
                }
                $createdDir = true;
            }

            foreach ($pagesToStore as $position => [$file, $newFilename, $imageHash]) {
                $failedName = $file->getClientOriginalName();
                $file->move($uploadDir, $newFilename);
                $movedPaths[] = $uploadDir . '/' . $newFilename;

                $relativePath = 'uploads/menu-imports/' . $restaurant->getId() . '/' . $batch->getId() . '/' . $newFilename;
                $page = new MenuImportPage($batch, $relativePath, $position, $imageHash);
                $em->persist($page);
                $batch->addPage($page);
                $pages[] = $page;
            }

            $em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            foreach ($movedPaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            if ($createdDir && $uploadDir !== null && is_dir($uploadDir)) {
                @rmdir($uploadDir);
            }
            foreach ([...$pages, $batch] as $entity) {
                if ($em->contains($entity)) {
                    $em->detach($entity);
                }
            }

            if ($e instanceof FileException) {
                return $this->uploadError('upload.error.storage_failed', ['%name%' => $failedName ?? '']);
            }
            throw $e;
        }

        $this->processingTrigger->trigger($batch);

        return $this->redirectToRoute('admin_menu_import_show', ['id' => $batch->getId()]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(MenuImportBatch $batch): Response
    {
        $this->assertOwner($batch);

        if ($this->isTerminal($batch)) {
            return $this->redirectToRoute('admin_menu_import_complete', ['id' => $batch->getId()]);
        }

        return $this->render('admin/menu_import_status.html.twig', [
            'batch' => $batch,
        ]);
    }

    #[Route('/{id}/status', name: 'status', methods: ['GET'])]
    public function statusJson(MenuImportBatch $batch): JsonResponse
    {
        $this->assertOwner($batch);

        return $this->json([
            'batchStatus' => $batch->getStatus()->value,
            'terminal' => $this->isTerminal($batch),
            'pages' => array_map(
                static fn (MenuImportPage $page) => [
                    'position' => $page->getPosition(),
                    'status' => $page->getStatus()->value,
                ],
                $batch->getPages()->toArray()
            ),
        ]);
    }

    #[Route('/{id}/complete', name: 'complete', methods: ['GET'])]
    public function complete(MenuImportBatch $batch, EntityManagerInterface $em, AiPhotoBatch $aiPhotoBatch, DishPhotoQuota $quota, AiAccess $aiAccess): Response
    {
        $this->assertOwner($batch);

        $categoriesCreated = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM category WHERE import_batch_id = ?',
            [$batch->getId()]
        );
        $productsCreated = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM product WHERE import_batch_id = ?',
            [$batch->getId()]
        );
        // First carta: published at once (MenuImportAssembler); dishes left
        // hidden there are the ones without a readable price.
        $productsVisible = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM product WHERE import_batch_id = ? AND active = true',
            [$batch->getId()]
        );
        // "Repetido" (MenuImportAssembler): hidden because the name was
        // already in its category. Not "sin precio" — counted apart.
        $duplicateNames = $em->getConnection()->fetchFirstColumn(
            'SELECT t.name FROM product p JOIN product_translation t ON t.product_id = p.id AND t.locale = ?
             WHERE p.import_batch_id = ? AND p.import_duplicate = true ORDER BY p.id',
            [$batch->getRestaurant()->getDefaultLanguage(), $batch->getId()]
        );
        $failedPages = array_filter(
            $batch->getPages()->toArray(),
            static fn (MenuImportPage $p) => $p->getStatus()->value === 'failed'
        );

        // "Crear sus fotos con IA": this import's dishes still without a
        // photo, unless the owner already chose "Dejarlo" for it. On the
        // trial the import is remembered, so Carta offers the rest once paid.
        $aiPhotoRemaining = $batch->getAiPhotosStatus() === MenuImportBatch::AI_PHOTOS_DISMISSED ? 0 : $aiPhotoBatch->pendingCount($batch);
        if ($aiPhotoRemaining > 0 && $batch->getStatus() === MenuImportBatchStatus::READY_FOR_REVIEW) {
            $aiPhotoBatch->markTrial($batch);
        }

        // Its public menu is off (expired, or "Pendiente de pago" while its
        // reseller prepares it): "Tu carta está lista", and the AI photo
        // box only if AiAccess still allows the free photos — else just
        // "N platos no tienen foto".
        $restaurant = $batch->getRestaurant();
        $menuOff = $restaurant->isExpired();
        $aiPhotoBox = $menuOff
            ? $aiPhotoRemaining > 0 && $aiPhotoBatch->isAvailable() && $aiAccess->allows($restaurant, AiAccess::PHOTOS)
            // Also with nothing left to do while the trial's photos are used
            // up: "Ya has usado tus 2 fotos con IA gratis" after every import.
            : $aiPhotoRemaining > 0 || ($aiPhotoBatch->isAvailable() && $quota->trialRemaining($restaurant) === 0);

        return $this->render('admin/menu_import_complete.html.twig', [
            'batch' => $batch,
            'menuOff' => $menuOff,
            'categoriesCreated' => $categoriesCreated,
            'productsCreated' => $productsCreated,
            'published' => $productsVisible > 0,
            'productsHidden' => $productsCreated - $productsVisible - count($duplicateNames),
            'duplicateCount' => count($duplicateNames),
            'duplicateNames' => array_slice($duplicateNames, 0, self::DUPLICATE_NAMES_SHOWN),
            'duplicateMore' => max(0, count($duplicateNames) - self::DUPLICATE_NAMES_SHOWN),
            'failedPageCount' => count($failedPages),
            // Every failed photo failed only because the AI service was
            // saturated: say so instead of blaming the photos.
            'temporaryFailure' => $failedPages !== [] && array_filter($failedPages, static fn (MenuImportPage $p) => !$p->isTemporaryFailure()) === [],
            'totalPageCount' => $batch->getPages()->count(),
            'aiPhotoRemaining' => $aiPhotoRemaining,
            'aiPhotoBox' => $aiPhotoBox,
        ]);
    }

    /**
     * "Traducir al {idioma principal}" on Carta's notice (see
     * MenuImportLanguageNotice): one batch per request, the browser calls
     * it again until done — like the main-language change.
     */
    #[Route('/{id}/language/translate', name: 'language_translate', methods: ['POST'])]
    #[RequiresAi(AiAccess::IMPORT)]
    public function translateLanguage(MenuImportBatch $batch, Request $request, MenuImportLanguageNotice $notice): JsonResponse
    {
        $this->assertOwner($batch);
        if (!$this->isCsrfTokenValid('import_language_' . $batch->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['error' => true], Response::HTTP_FORBIDDEN);
        }

        $result = $notice->step($batch);
        if ($result['done'] && $batch->getLanguageNotice() === MenuImportBatch::NOTICE_TRANSLATED) {
            $this->addFlash('success', $this->translator->trans('language_notice.done', [
                '%language%' => LanguageChangeController::languageName($batch->getRestaurant()->getDefaultLanguage(), $batch->getRestaurant()->getAdminLocale()),
            ], 'admin_menu'));
        }

        return $this->json($result);
    }

    /** "Dejarla así": nothing changes, the notice is gone for this import. */
    #[Route('/{id}/language/dismiss', name: 'language_dismiss', methods: ['POST'])]
    public function dismissLanguage(MenuImportBatch $batch, Request $request, MenuImportLanguageNotice $notice): Response
    {
        $this->assertOwner($batch);
        if ($this->isCsrfTokenValid('import_language_' . $batch->getId(), (string) $request->request->get('_token'))) {
            $notice->dismiss($batch);
        }

        return $this->redirectToRoute('admin_menu');
    }

    private function assertOwner(MenuImportBatch $batch): void
    {
        if ($batch->getRestaurant() !== $this->restaurant()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function isTerminal(MenuImportBatch $batch): bool
    {
        return in_array($batch->getStatus(), [
            MenuImportBatchStatus::READY_FOR_REVIEW,
            MenuImportBatchStatus::COMPLETED,
            MenuImportBatchStatus::FAILED,
        ], true);
    }

    private function uploadError(string $key, array $parameters = []): Response
    {
        $this->addFlash('error', $this->translator->trans($key, $parameters, domain: 'admin_menu_import'));

        return $this->redirectToRoute('admin_menu_import_new');
    }

    private function looksLikePdf(UploadedFile $file): bool
    {
        return strtolower($file->getClientOriginalExtension()) === 'pdf'
            || ($file->isValid() && $file->getMimeType() === 'application/pdf');
    }

    private function pdfErrorKey(MenuImportPdfError $error): string
    {
        return match ($error) {
            MenuImportPdfError::PasswordProtected => 'upload.error.pdf_password',
            MenuImportPdfError::Unreadable => 'upload.error.pdf_unreadable',
            MenuImportPdfError::NoPages => 'upload.error.pdf_no_pages',
        };
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }

    /** "66M" / "1G" / "512K" / "1048576" → bytes; 0 means "no limit". */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function importErrorKey(UploadValidationError $error): string
    {
        return match ($error) {
            UploadValidationError::UnsupportedType => 'upload.error.unsupported_type',
            UploadValidationError::TooLarge => 'upload.error.too_large',
            UploadValidationError::InvalidFile,
            UploadValidationError::DimensionsTooSmall,
            UploadValidationError::DimensionsTooLarge,
            UploadValidationError::Rejected => 'upload.error.invalid_file',
        };
    }
}
