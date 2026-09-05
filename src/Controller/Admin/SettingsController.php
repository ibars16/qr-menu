<?php

namespace App\Controller\Admin;

use App\Entity\Restaurant;
use App\Service\AdminLocaleResolver;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadValidationError;
use App\Service\Upload\UploadValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_OWNER')]
class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'settings')]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        UploadValidator $uploadValidator,
        AdminLocaleResolver $adminLocaleResolver,
        TranslatorInterface $translator,
    ): Response {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException();
        }

        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';
        $currencies = require $this->getParameter('kernel.project_dir') . '/config/currencies.php';

        if ($request->isMethod('POST')) {
            $name        = trim($request->request->get('name', ''));
            $tagline     = trim($request->request->get('tagline', ''));
            $color       = $request->request->get('primaryColor', '#C1440E');
            $currency    = $request->request->get('currency', 'EUR');
            $language    = $request->request->get('defaultLanguage', 'es');
            $adminLocale = $request->request->get('adminLocale', $adminLocaleResolver->getDefaultLocale());

            if (!$name) {
                $this->addFlash('error', $translator->trans('flash.name_required', domain: 'admin_settings'));
                return $this->redirectToRoute('admin_settings');
            }

            $restaurant->setName($name);
            $restaurant->setTagline($tagline !== '' ? $tagline : null);
            $restaurant->setPrimaryColor($color);
            $restaurant->setCurrency($currency);
            $restaurant->setDefaultLanguage($language);
            $restaurant->setAdminLocale($adminLocaleResolver->resolve($adminLocale));

            // Handle logo upload
            $logoFile = $request->files->get('logo');
            if ($logoFile) {
                $result = $uploadValidator->validate($logoFile, UploadProfile::Logo);
                if (!$result->isValid) {
                    $this->addFlash('error', $translator->trans($this->logoErrorKey($result->error), domain: 'admin_settings'));
                    return $this->redirectToRoute('admin_settings');
                }

                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/logos';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $oldLogo = $restaurant->getLogo();

                try {
                    $logoFile->move($uploadDir, $result->safeFilename);
                    $restaurant->setLogo($result->safeFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', $translator->trans('flash.logo_upload_error', domain: 'admin_settings'));
                    return $this->redirectToRoute('admin_settings');
                }

                // Old file is orphaned on disk once the column no longer
                // points to it — clean it up now that the new one is safely
                // in place.
                if ($oldLogo) {
                    $oldPath = $uploadDir . '/' . $oldLogo;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
            }

            // Handle logo removal
            if ($request->request->get('removeLogoFlag') === '1') {
                $restaurant->setLogo(null);
            }

            // name/tagline/logo/primaryColor/currency/defaultLanguage all
            // appear on the public menu; adminLocale is the only field here
            // that doesn't. One flush covers all of them, so bump
            // unconditionally rather than trying to detect which fields
            // actually changed.
            $restaurant->bumpMenuContentVersion();
            $em->flush();
            $this->addFlash('success', $translator->trans('flash.saved', domain: 'admin_settings'));
            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'restaurant'   => $restaurant,
            'languages'    => $languages,
            'currencies'   => $currencies,
            'adminLocales' => $adminLocaleResolver->getLocales(),
        ]);
    }

    private function logoErrorKey(UploadValidationError $error): string
    {
        return match ($error) {
            UploadValidationError::UnsupportedType => 'flash.logo_unsupported_type',
            UploadValidationError::TooLarge => 'flash.logo_too_large',
            UploadValidationError::DimensionsTooSmall => 'flash.logo_dimensions_too_small',
            UploadValidationError::DimensionsTooLarge => 'flash.logo_dimensions_too_large',
            UploadValidationError::InvalidFile, UploadValidationError::Rejected => 'flash.logo_upload_error',
        };
    }
}
