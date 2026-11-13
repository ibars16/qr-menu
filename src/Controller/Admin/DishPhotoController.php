<?php

namespace App\Controller\Admin;

use App\Entity\MenuImportBatch;
use App\Entity\Product;
use App\Entity\Restaurant;
use App\Service\DishPhoto\AiPhotoBatch;
use App\Service\DishPhoto\DishPhotoException;
use App\Service\DishPhoto\DishPhotoMessages;
use App\Service\DishPhoto\DishPhotoGenerator;
use App\Service\DishPhoto\DishPhotoQuota;
use App\Service\DishPhoto\DishPhotoStorage;
use App\Service\Upload\ProductImageReplacer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dish photos generated with AI (App\Service\DishPhoto):
 *
 * - editor, Foto tab: generate() makes a DRAFT from the dish's name and
 *   description (the dish may not exist yet), draft() shows it, and
 *   apply() puts it on the dish (at once for a saved dish, on save for a
 *   new one).
 * - "Mejorar con IA" (enhance()): a saved dish only, applied at once, the
 *   owner's original kept aside until revert() ("Volver a la original")
 *   or any other change of photo (ProductImageReplacer).
 * - after an import: importStep() does one dish per request, the browser
 *   repeats it until done (resumable from Carta); importDismiss() hides it.
 *
 * Route names stay under admin_product_ / admin_menu_ so EmptyMenuRedirect
 * lets them through with an empty carta.
 */
#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_STAFF')]
class DishPhotoController extends AbstractController
{
    public function __construct(
        private readonly DishPhotoGenerator $generator,
        private readonly DishPhotoQuota $quota,
        private readonly DishPhotoStorage $storage,
        private readonly ProductImageReplacer $replacer,
        private readonly AiPhotoBatch $batch,
        private readonly DishPhotoMessages $messages,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/products/ai-photo/generate', name: 'product_ai_photo_generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        $restaurant = $this->restaurant();
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => $this->trans('ai_photo.error.name_required'), 'reason' => 'name_required'], Response::HTTP_BAD_REQUEST);
        }
        $description = trim((string) ($data['description'] ?? '')) ?: null;

        $productId = isset($data['productId']) && $data['productId'] !== null ? (int) $data['productId'] : null;
        if ($productId !== null) {
            $product = $this->em->find(Product::class, $productId);
            if (!$product || $product->getCategory()->getRestaurant() !== $restaurant) {
                throw $this->createAccessDeniedException();
            }
        }

        try {
            $token = $this->generator->draft($restaurant, $name, $description, $productId);
        } catch (DishPhotoException $e) {
            return $this->failure($e, $restaurant);
        }

        return $this->json([
            'token' => $token,
            'preview' => $this->generateUrl('admin_product_ai_photo_draft', ['token' => $token]),
            'trialRemaining' => $this->quota->trialRemaining($restaurant),
        ]);
    }

    /** "Mejorar con IA": the dish's photo retouched and put on it at once (same quota as generate()). */
    #[Route('/products/{id}/ai-photo/enhance', name: 'product_ai_photo_enhance', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function enhance(Product $product): JsonResponse
    {
        $restaurant = $this->restaurant();
        if ($product->getCategory()->getRestaurant() !== $restaurant) {
            throw $this->createAccessDeniedException();
        }

        try {
            $filename = $this->generator->enhance($product);
        } catch (DishPhotoException $e) {
            return $this->failure($e, $restaurant);
        }
        $this->replacer->applyEnhanced($product, $filename);
        $this->em->flush();

        return $this->json([
            'image' => $filename,
            'videoClip' => null,
            'imageSource' => Product::IMAGE_SOURCE_AI_EDIT,
            'canRevert' => true,
            'trialRemaining' => $this->quota->trialRemaining($restaurant),
        ]);
    }

    /** "Volver a la original": the photo kept by enhance() back on the dish. */
    #[Route('/products/{id}/image/original', name: 'product_image_original', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revert(Product $product): JsonResponse
    {
        if ($product->getCategory()->getRestaurant() !== $this->restaurant()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->replacer->restoreOriginal($product)) {
            return $this->json(['error' => $this->trans('ai_photo.error.no_original'), 'reason' => 'no_original'], Response::HTTP_GONE);
        }
        $this->em->flush();

        return $this->json(['image' => $product->getImage(), 'videoClip' => null, 'imageSource' => null, 'canRevert' => false]);
    }

    #[Route('/products/ai-photo/draft/{token}', name: 'product_ai_photo_draft', methods: ['GET'])]
    public function draft(string $token): Response
    {
        $path = $this->storage->draftPath($this->restaurant(), $token);
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[Route('/products/{id}/image/ai', name: 'product_ai_photo_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function apply(Product $product, Request $request): JsonResponse
    {
        $restaurant = $this->restaurant();
        if ($product->getCategory()->getRestaurant() !== $restaurant) {
            throw $this->createAccessDeniedException();
        }

        $data = json_decode($request->getContent(), true);
        $filename = $this->storage->promoteDraft($restaurant, (string) (is_array($data) ? ($data['token'] ?? '') : ''));
        if ($filename === null) {
            return $this->json(['error' => $this->trans('ai_photo.error.draft_expired'), 'reason' => 'draft_expired'], Response::HTTP_GONE);
        }

        $this->replacer->replace($product, $filename, Product::IMAGE_SOURCE_AI);
        $this->em->flush();

        return $this->json(['image' => $filename, 'videoClip' => null, 'imageSource' => Product::IMAGE_SOURCE_AI, 'canRevert' => false]);
    }

    #[Route('/menu/import/{id}/photos/step', name: 'menu_import_photos_step', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function importStep(MenuImportBatch $importBatch, Request $request): JsonResponse
    {
        $restaurant = $this->assertBatch($importBatch);
        if (!$this->isCsrfTokenValid('import_photos_' . $importBatch->getId(), (string) $request->request->get('_token'))) {
            return $this->json(['error' => true], Response::HTTP_FORBIDDEN);
        }

        $result = $this->batch->step($importBatch);

        return $this->json($result + [
            'message' => $result['stopped'] !== null ? $this->messages->forReason($result['stopped']) : null,
            'trialRemaining' => $this->quota->trialRemaining($restaurant),
        ]);
    }

    #[Route('/menu/import/{id}/photos/dismiss', name: 'menu_import_photos_dismiss', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function importDismiss(MenuImportBatch $importBatch, Request $request): Response
    {
        $this->assertBatch($importBatch);
        if ($this->isCsrfTokenValid('import_photos_' . $importBatch->getId(), (string) $request->request->get('_token'))) {
            $this->batch->dismiss($importBatch);
        }

        return $this->redirectToRoute('admin_menu');
    }

    private function failure(DishPhotoException $e, Restaurant $restaurant): JsonResponse
    {
        $status = match ($e->reason) {
            DishPhotoException::REASON_TRIAL_LIMIT, DishPhotoException::REASON_CARD_PENDING => Response::HTTP_PAYMENT_REQUIRED,
            DishPhotoException::REASON_RATE_LIMITED, DishPhotoException::REASON_MONTHLY_LIMIT => Response::HTTP_TOO_MANY_REQUESTS,
            DishPhotoException::REASON_NO_OWNER_PHOTO => Response::HTTP_CONFLICT,
            'timeout' => Response::HTTP_GATEWAY_TIMEOUT,
            'unavailable', 'not_configured' => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_UNPROCESSABLE_ENTITY,
        };

        return $this->json([
            'error' => $this->messages->forReason($e->reason),
            'reason' => $e->reason,
            'trialRemaining' => $this->quota->trialRemaining($restaurant),
        ], $status);
    }

    private function assertBatch(MenuImportBatch $importBatch): Restaurant
    {
        $restaurant = $this->restaurant();
        if ($importBatch->getRestaurant() !== $restaurant) {
            throw $this->createAccessDeniedException();
        }

        return $restaurant;
    }

    private function restaurant(): Restaurant
    {
        return $this->getUser()?->getRestaurant() ?? throw $this->createAccessDeniedException();
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, domain: 'admin_menu');
    }
}
