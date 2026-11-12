<?php

namespace App\Controller\Admin;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use App\Service\BatchProcessingTriggerInterface;
use App\Service\DishPhoto\AiPhotoBatch;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Service\MenuImportLanguageNotice;
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
    private const MAX_FILES_PER_UPLOAD = 30;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly BatchProcessingTriggerInterface $processingTrigger,
        private readonly UploadValidator $uploadValidator,
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
    public function upload(Request $request, EntityManagerInterface $em): Response
    {
        $restaurant = $this->restaurant();

        /** @var UploadedFile[] $files */
        $files = $request->files->all('images') ?? [];

        if (empty($files)) {
            $this->addFlash('error', $this->translator->trans('upload.error.no_files', domain: 'admin_menu_import'));
            return $this->redirectToRoute('admin_menu_import_new');
        }

        if (count($files) > self::MAX_FILES_PER_UPLOAD) {
            $this->addFlash('error', $this->translator->trans('upload.error.too_many_files', ['%max%' => self::MAX_FILES_PER_UPLOAD], domain: 'admin_menu_import'));
            return $this->redirectToRoute('admin_menu_import_new');
        }

        // Validate every file up front — before the batch is even created —
        // so a bad file in the middle of a multi-file upload never leaves a
        // half-populated batch behind.
        $validatedFiles = [];
        foreach ($files as $file) {
            $result = $this->uploadValidator->validate($file, UploadProfile::MenuImportPage);
            if (!$result->isValid) {
                $this->addFlash('error', $this->translator->trans($this->importErrorKey($result->error), ['%name%' => $file->getClientOriginalName()], domain: 'admin_menu_import'));
                return $this->redirectToRoute('admin_menu_import_new');
            }
            $validatedFiles[] = [$file, $result];
        }

        $batch = new MenuImportBatch($restaurant);
        $em->persist($batch);
        $em->flush(); // assign an id — used in the storage path below

        $uploadDir = $this->getParameter('app.uploads_dir')
            . '/menu-imports/' . $restaurant->getId() . '/' . $batch->getId();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach ($validatedFiles as $position => [$file, $result]) {
            $newFilename = $result->safeFilename;
            $imageHash = hash_file('sha256', $file->getPathname());

            try {
                $file->move($uploadDir, $newFilename);
            } catch (FileException) {
                $this->addFlash('error', $this->translator->trans('upload.error.storage_failed', ['%name%' => $file->getClientOriginalName()], domain: 'admin_menu_import'));
                return $this->redirectToRoute('admin_menu_import_new');
            }

            $relativePath = 'uploads/menu-imports/' . $restaurant->getId() . '/' . $batch->getId() . '/' . $newFilename;
            $page = new MenuImportPage($batch, $relativePath, $position, $imageHash);
            $em->persist($page);
            $batch->addPage($page);
        }

        $em->flush();

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
    public function complete(MenuImportBatch $batch, EntityManagerInterface $em, AiPhotoBatch $aiPhotoBatch, DishPhotoQuota $quota): Response
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

        return $this->render('admin/menu_import_complete.html.twig', [
            'batch' => $batch,
            'categoriesCreated' => $categoriesCreated,
            'productsCreated' => $productsCreated,
            'published' => $productsVisible > 0,
            'productsHidden' => $productsCreated - $productsVisible,
            'failedPageCount' => count($failedPages),
            'totalPageCount' => $batch->getPages()->count(),
            'aiPhotoRemaining' => $aiPhotoRemaining,
            // Also with nothing left to do while the trial's photos are used
            // up: "Ya has usado tus 2 fotos con IA gratis" after every import.
            'aiPhotoBox' => $aiPhotoRemaining > 0 || ($aiPhotoBatch->isAvailable() && $quota->trialRemaining($batch->getRestaurant()) === 0),
        ]);
    }

    /**
     * "Traducir al {idioma principal}" on Carta's notice (see
     * MenuImportLanguageNotice): one batch per request, the browser calls
     * it again until done — like the main-language change.
     */
    #[Route('/{id}/language/translate', name: 'language_translate', methods: ['POST'])]
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
