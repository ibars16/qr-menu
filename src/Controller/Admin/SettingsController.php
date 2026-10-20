<?php

namespace App\Controller\Admin;

use App\Entity\Restaurant;
use App\Service\AdminLocaleResolver;
use App\Service\ContactDetailsNormalizer;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadQualityWarning;
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
    /** Values of the per-section save buttons (name="section"). */
    private const SECTIONS = ['info', 'contact', 'social', 'hours', 'appearance', 'language'];

    #[Route('/settings', name: 'settings')]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        UploadValidator $uploadValidator,
        AdminLocaleResolver $adminLocaleResolver,
        TranslatorInterface $translator,
        ContactDetailsNormalizer $contactNormalizer,
    ): Response {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException();
        }

        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';
        $currencies = require $this->getParameter('kernel.project_dir') . '/config/currencies.php';

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('admin_settings', (string) $request->request->get('_token'))) {
                $this->addFlash('error', $translator->trans('flash.csrf_invalid', domain: 'admin_settings'));
                return $this->redirectToRoute('admin_settings');
            }

            $name        = trim($request->request->get('name', ''));
            $tagline     = trim($request->request->get('tagline', ''));
            $color       = $request->request->get('primaryColor', '#C1440E');
            $currency    = $request->request->get('currency', 'EUR');
            $language    = $request->request->get('defaultLanguage', 'es');
            $adminLocale = $request->request->get('adminLocale', $adminLocaleResolver->getDefaultLocale());

            // Each section has its own "Guardar" button (name="section");
            // the footer button posts no section and saves everything. A
            // per-section save only validates and applies that section's
            // fields — whatever was typed elsewhere is left unsaved.
            $section = $request->request->get('section');
            if (!in_array($section, self::SECTIONS, true)) {
                $section = null;
            }
            $saves = static fn (string $s): bool => $section === null || $section === $s;

            // Every text field is validated before anything is saved or any
            // file is moved; on error the form is re-rendered with what was
            // typed and a message under each failing field (nothing saved).
            $errors = [];
            if ($saves('info') && $name === '') {
                $errors['name'] = 'error.name_required';
            }
            if ($saves('info') && mb_strlen($tagline) > 160) {
                $errors['tagline'] = 'error.tagline_too_long';
            }

            $socialInput = $request->request->all('social');
            $hoursInput  = $request->request->all('hours');

            if ($saves('contact')) {
                [$address, $error] = $contactNormalizer->normalizeAddress((string) $request->request->get('address', ''));
                if ($error) {
                    $errors['address'] = 'error.address_' . $error;
                }
                [$phone, $error] = $contactNormalizer->normalizePhone((string) $request->request->get('phone', ''));
                if ($error) {
                    $errors['phone'] = 'error.phone_' . $error;
                }
            }

            if ($saves('social')) {
                $socialLinks = [];
                foreach (ContactDetailsNormalizer::NETWORKS as $network) {
                    [$url, $error] = $contactNormalizer->normalizeSocial($network, (string) ($socialInput[$network] ?? ''));
                    if ($error) {
                        $errors['social_' . $network] = 'error.social_' . $error;
                    } elseif ($url !== null) {
                        $socialLinks[$network] = $url;
                    }
                }
            }

            if ($saves('hours')) {
                [$openingHours, $hourErrors] = $contactNormalizer->normalizeOpeningHours($hoursInput);
                foreach ($hourErrors as $day => $error) {
                    $errors['hours_' . $day] = 'error.hours_' . $error;
                }
            }

            if ($errors !== []) {
                $this->addFlash('error', $translator->trans('flash.form_errors', domain: 'admin_settings'));

                return $this->render('admin/settings.html.twig', [
                    'restaurant'            => $restaurant,
                    'languages'             => $languages,
                    'currencies'            => $currencies,
                    'adminLocales'          => $adminLocaleResolver->getLocales(),
                    'heroImageMinDimension' => UploadValidator::minDimension(UploadProfile::HeroImage),
                    'errors'                => $errors,
                    'old'                   => [
                        'name'    => $name,
                        'tagline' => $tagline,
                        'address' => (string) $request->request->get('address', ''),
                        'phone'   => (string) $request->request->get('phone', ''),
                        'social'  => array_map('strval', array_filter($socialInput, 'is_scalar')),
                        'hours'   => $hoursInput,
                        // Re-selected as posted; the colour goes into a
                        // style attribute, so only a well-formed hex is echoed.
                        'primaryColor'    => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? $color : $restaurant->getPrimaryColor(),
                        'defaultLanguage' => $language,
                        'adminLocale'     => $adminLocale,
                        'currency'        => $currency,
                    ],
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            if ($saves('info')) {
                $restaurant->setName($name);
                $restaurant->setTagline($tagline !== '' ? $tagline : null);
            }
            if ($saves('contact')) {
                $restaurant->setAddress($address);
                $restaurant->setPhone($phone);
            }
            if ($saves('social')) {
                $restaurant->setSocialLinks($socialLinks);
            }
            if ($saves('hours')) {
                $restaurant->setOpeningHours($openingHours);
            }
            if ($saves('appearance')) {
                $restaurant->setPrimaryColor($color);
            }
            if ($saves('language')) {
                $restaurant->setCurrency($currency);
                $restaurant->setDefaultLanguage($language);
                $restaurant->setAdminLocale($adminLocaleResolver->resolve($adminLocale));
            }

            // Handle logo upload
            $logoFile = $saves('appearance') ? $request->files->get('logo') : null;
            if ($logoFile) {
                $result = $uploadValidator->validate($logoFile, UploadProfile::Logo);
                if (!$result->isValid) {
                    $this->addFlash('error', $translator->trans($this->logoErrorKey($result->error), domain: 'admin_settings'));
                    return $this->redirectToRoute('admin_settings');
                }

                $uploadDir = $this->getParameter('app.uploads_dir') . '/logos';
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
            if ($saves('appearance') && $request->request->get('removeLogoFlag') === '1') {
                $restaurant->setLogo(null);
            }

            // Handle hero image upload — same validate/move/delete-old
            // pattern as the logo above, but its own directory (never
            // scanned by app:logos:clean-orphans) and its own upload
            // profile (8MB, 300-5000px): lower minimum than dish photos on
            // purpose (see UploadProfile::HeroImage's own comment) — a hero
            // band just needs a not-tiny image, not the same bar as a
            // dish photo meant to be cropped square/tall.
            $heroImageFile = $saves('appearance') ? $request->files->get('heroImage') : null;
            if ($heroImageFile) {
                // Only ever lets a QUALITY warning (small/portrait) through —
                // see UploadValidator::validate()'s own docblock for why this
                // can't reach the security checks above it.
                $heroQualityConfirmed = $request->request->get('heroImageQualityConfirmed') === '1';
                $result = $uploadValidator->validate($heroImageFile, UploadProfile::HeroImage, qualityWarningsConfirmed: $heroQualityConfirmed);

                if (!$result->isValid && $result->error !== null) {
                    // Fatal — a real UploadValidationError, never overridable.
                    $this->addFlash('error', $translator->trans(
                        $this->heroImageErrorKey($result->error),
                        $this->heroImageErrorParams($result->error),
                        domain: 'admin_settings'
                    ));
                    return $this->redirectToRoute('admin_settings');
                }

                if (!$result->isValid) {
                    // error === null here: quality warnings only, not yet
                    // confirmed. Nothing is saved — not even the other
                    // fields in this same submission, same as any other
                    // early-return above — the owner re-submits (ticking
                    // "upload anyway", see settings.html.twig) to proceed.
                    foreach ($result->warnings as $warning) {
                        $this->addFlash('warning', $translator->trans($this->heroImageWarningKey($warning), domain: 'admin_settings'));
                    }
                    return $this->redirectToRoute('admin_settings');
                }

                $heroUploadDir = $this->getParameter('app.uploads_dir') . '/heroes';
                if (!is_dir($heroUploadDir)) {
                    mkdir($heroUploadDir, 0755, true);
                }

                $oldHeroImage = $restaurant->getHeroImage();

                try {
                    $heroImageFile->move($heroUploadDir, $result->safeFilename);
                    $restaurant->setHeroImage($result->safeFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', $translator->trans('flash.hero_image_upload_error', domain: 'admin_settings'));
                    return $this->redirectToRoute('admin_settings');
                }

                // Old file is orphaned on disk once the column no longer
                // points to it — clean it up now that the new one is safely
                // in place.
                if ($oldHeroImage) {
                    $oldHeroPath = $heroUploadDir . '/' . $oldHeroImage;
                    if (is_file($oldHeroPath)) {
                        unlink($oldHeroPath);
                    }
                }
            }

            // Handle hero image removal
            if ($saves('appearance') && $request->request->get('removeHeroImageFlag') === '1') {
                $restaurant->setHeroImage(null);
            }

            // name/tagline/logo/heroImage/primaryColor/currency/defaultLanguage
            // (and the contact details, for the menu footer) appear on the
            // public menu; adminLocale is the only field here
            // that doesn't. One flush covers all of them, so bump
            // unconditionally rather than trying to detect which fields
            // actually changed.
            $restaurant->bumpMenuContentVersion();
            $em->flush();
            $this->addFlash('success', $translator->trans($section ? 'flash.section_saved' : 'flash.saved', domain: 'admin_settings'));

            // Back to the section just saved, not the top of a long page.
            return $this->redirectToRoute('admin_settings', $section ? ['_fragment' => 'section-' . $section] : []);
        }

        return $this->render('admin/settings.html.twig', [
            'restaurant'            => $restaurant,
            'languages'             => $languages,
            'currencies'            => $currencies,
            'adminLocales'          => $adminLocaleResolver->getLocales(),
            'heroImageMinDimension' => UploadValidator::minDimension(UploadProfile::HeroImage),
            'errors'                => [],
            'old'                   => null,
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

    private function heroImageErrorKey(UploadValidationError $error): string
    {
        return match ($error) {
            UploadValidationError::UnsupportedType => 'flash.hero_image_unsupported_type',
            UploadValidationError::TooLarge => 'flash.hero_image_too_large',
            UploadValidationError::DimensionsTooSmall => 'flash.hero_image_dimensions_too_small',
            UploadValidationError::DimensionsTooLarge => 'flash.hero_image_dimensions_too_large',
            UploadValidationError::InvalidFile, UploadValidationError::Rejected => 'flash.hero_image_upload_error',
        };
    }

    /**
     * Only the "too small" message is actionable with a number — it tells
     * the owner what to do (upload one at least this big), not just what
     * failed. The number comes from UploadValidator::minDimension(), never
     * hardcoded here or in the translation string, so it can't drift out of
     * sync with the profile's actual limit.
     */
    private function heroImageErrorParams(UploadValidationError $error): array
    {
        return match ($error) {
            UploadValidationError::DimensionsTooSmall => ['%min%' => UploadValidator::minDimension(UploadProfile::HeroImage)],
            default => [],
        };
    }

    private function heroImageWarningKey(UploadQualityWarning $warning): string
    {
        return match ($warning) {
            UploadQualityWarning::DimensionsBelowRecommended => 'flash.hero_image_warning_dimensions',
            UploadQualityWarning::PortraitAspectRatio => 'flash.hero_image_warning_portrait',
        };
    }
}
