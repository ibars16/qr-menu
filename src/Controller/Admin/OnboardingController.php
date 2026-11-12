<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Personaliza tu restaurante": the optional step right after a self
 * sign-up (RegistrationController, ResellerSignupController) — the short
 * description (Restaurant::$tagline), logo and cover photo, all optional,
 * with "Saltar". The same fields stay editable in Configuración.
 *
 * Uploads go through the same UploadValidator profiles as Configuración.
 * The cover photo's quality warnings (small / very vertical) don't stop
 * it here: it's saved and the warning shown, so the step never asks twice.
 */
#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_OWNER')]
class OnboardingController extends AbstractController
{
    private const TAGLINE_MAX = 160;

    #[Route('/bienvenida', name: 'onboarding', methods: ['GET', 'POST'])]
    public function onboarding(Request $request, EntityManagerInterface $em, UploadValidator $uploadValidator, TranslatorInterface $translator): Response
    {
        $user = $this->getUser();
        $restaurant = $user instanceof User ? $user->getRestaurant() : null;
        if ($restaurant === null) {
            return $this->redirectToRoute('admin_menu');
        }

        $errors = [];
        $tagline = (string) ($restaurant->getTagline() ?? '');

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_onboarding', (string) $request->request->get('_token'))) {
                return $this->redirectToRoute('admin_onboarding');
            }

            $tagline = trim((string) $request->request->get('tagline', ''));
            if (mb_strlen($tagline) > self::TAGLINE_MAX) {
                $errors['tagline'] = $translator->trans('error.tagline_too_long', [], 'admin_settings');
            }

            // Validate both files before either touches the disk.
            $files = [];
            $logo = $request->files->get('logo');
            if ($logo instanceof UploadedFile) {
                $result = $uploadValidator->validate($logo, UploadProfile::Logo);
                if (!$result->isValid) {
                    $errors['logo'] = $translator->trans(SettingsController::logoErrorKey($result->error), [], 'admin_settings');
                } else {
                    $files[] = [$logo, 'logos', $result->safeFilename, $restaurant->getLogo(), $restaurant->setLogo(...)];
                }
            }
            $hero = $request->files->get('heroImage');
            $heroWarned = false;
            if ($hero instanceof UploadedFile) {
                $result = $uploadValidator->validate($hero, UploadProfile::HeroImage, qualityWarningsConfirmed: true);
                if (!$result->isValid && $result->error !== null) {
                    $errors['heroImage'] = $translator->trans(SettingsController::heroImageErrorKey($result->error), SettingsController::heroImageErrorParams($result->error), 'admin_settings');
                } else {
                    $heroWarned = $result->warnings !== [];
                    $files[] = [$hero, 'heroes', $result->safeFilename, $restaurant->getHeroImage(), $restaurant->setHeroImage(...)];
                }
            }

            if ($errors === []) {
                $moved = [];
                $old = [];
                foreach ($files as [$file, $dir, $name, $previous, $set]) {
                    $path = $this->getParameter('app.uploads_dir') . '/' . $dir;
                    if (!is_dir($path)) {
                        mkdir($path, 0755, true);
                    }
                    try {
                        $file->move($path, $name);
                    } catch (FileException) {
                        array_map(static fn (string $p) => is_file($p) && unlink($p), $moved);
                        $errors['upload'] = $translator->trans('onboarding.upload_error', [], 'admin_onboarding');
                        break;
                    }
                    $moved[] = $path . '/' . $name;
                    $set($name);
                    if ($previous) {
                        $old[] = $path . '/' . $previous;
                    }
                }

                if ($errors === []) {
                    $restaurant->setTagline($tagline !== '' ? $tagline : null);
                    $restaurant->bumpMenuContentVersion();
                    $em->flush();
                    foreach ($old as $p) {
                        if (is_file($p)) {
                            unlink($p);
                        }
                    }
                    if ($heroWarned) {
                        $this->addFlash('warning', $translator->trans('onboarding.hero_quality', [], 'admin_onboarding'));
                    }

                    return $this->redirectToRoute('admin_menu');
                }
            }
        }

        return $this->render('admin/onboarding.html.twig', [
            'restaurant' => $restaurant,
            'tagline' => $tagline,
            'taglineMax' => self::TAGLINE_MAX,
            'errors' => $errors,
        ], new Response(status: $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
