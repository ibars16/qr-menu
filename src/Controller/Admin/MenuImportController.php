<?php

namespace App\Controller\Admin;

use App\Entity\MenuImportBatch;
use App\Entity\MenuImportPage;
use App\Entity\Restaurant;
use App\Enum\MenuImportBatchStatus;
use App\Service\BatchProcessingTriggerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
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
#[IsGranted('ROLE_USER')]
class MenuImportController extends AbstractController
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_FILES_PER_UPLOAD = 30;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly BatchProcessingTriggerInterface $processingTrigger,
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
    public function upload(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
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

        foreach ($files as $file) {
            if (!$file->isValid()) {
                $this->addFlash('error', $this->translator->trans('upload.error.invalid_file', ['%name%' => $file->getClientOriginalName()], domain: 'admin_menu_import'));
                return $this->redirectToRoute('admin_menu_import_new');
            }
            if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
                $this->addFlash('error', $this->translator->trans('upload.error.unsupported_type', ['%name%' => $file->getClientOriginalName()], domain: 'admin_menu_import'));
                return $this->redirectToRoute('admin_menu_import_new');
            }
        }

        $batch = new MenuImportBatch($restaurant);
        $em->persist($batch);
        $em->flush(); // assign an id — used in the storage path below

        $uploadDir = $this->getParameter('kernel.project_dir')
            . '/public/uploads/menu-imports/' . $restaurant->getId() . '/' . $batch->getId();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        foreach (array_values($files) as $position => $file) {
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $slugger->slug($originalFilename);
            $newFilename = $position . '-' . $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();
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
    public function complete(MenuImportBatch $batch, EntityManagerInterface $em): Response
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
        $failedPages = array_filter(
            $batch->getPages()->toArray(),
            static fn (MenuImportPage $p) => $p->getStatus()->value === 'failed'
        );

        return $this->render('admin/menu_import_complete.html.twig', [
            'batch' => $batch,
            'categoriesCreated' => $categoriesCreated,
            'productsCreated' => $productsCreated,
            'failedPageCount' => count($failedPages),
            'totalPageCount' => $batch->getPages()->count(),
        ]);
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
}
