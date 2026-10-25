<?php

namespace App\Controller\Admin;

use App\Service\AdminLocaleResolver;
use App\Service\ContactDetailsNormalizer;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadQualityWarning;
use App\Service\Upload\UploadValidationError;
use App\Service\Upload\UploadValidationResult;
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
                    'heroImageRecommendedWidth' => UploadValidator::recommendedWidth(UploadProfile::HeroImage),
                    'heroImageMaxDimension' => UploadValidator::maxDimension(UploadProfile::HeroImage),
                    'heroImageMaxMegabytes' => UploadValidator::maxSizeMegabytes(UploadProfile::HeroImage),
                    'errors'                => $errors,
                    'discardedUploads'      => $this->discardedUploads($request, $section),
                    'old'                   => [
                        'name'    => $name,
                        'tagline' => $tagline,
                        'address' => (string) $request->request->get('address', ''),
                        'phone'   => (string) $request->request->get('phone', ''),
                        'social'  => array_map('strval', array_filter($socialInput, 'is_scalar')),
                        'hours'   => $hoursInput,
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
            if ($saves('language')) {
                $restaurant->setCurrency($currency);
                $restaurant->setDefaultLanguage($language);
                $restaurant->setAdminLocale($adminLocaleResolver->resolve($adminLocale));
            }

            // Logo and hero image. Both are validated before either file
            // touches the disk: a rejected or still-to-confirm hero must not
            // leave a half-applied logo behind (it used to move the new logo
            // and delete the old one, then return without flushing — the
            // restaurant kept pointing at a deleted file). Old files are only
            // deleted once the new names are flushed.
            $logoFile = $saves('appearance') ? $request->files->get('logo') : null;
            $logoResult = null;
            if ($logoFile) {
                $logoResult = $uploadValidator->validate($logoFile, UploadProfile::Logo);
                if (!$logoResult->isValid) {
                    $this->addFlash('error', $translator->trans($this->logoErrorKey($logoResult->error), domain: 'admin_settings'));
                    return $this->redirectToRoute('admin_settings');
                }
            }

            // Own profile (8MB, 300-5000px): lower minimum than dish photos
            // on purpose (see UploadProfile::HeroImage's own comment) — a
            // hero band just needs a not-tiny image. Its own directory, never
            // scanned by app:logos:clean-orphans.
            $heroImageFile = $saves('appearance') ? $request->files->get('heroImage') : null;
            $heroResult = null;
            if ($heroImageFile) {
                // Only ever lets a QUALITY warning (small/portrait) through —
                // see UploadValidator::validate()'s own docblock for why this
                // can't reach the security checks above it.
                $heroQualityConfirmed = $request->request->get('heroImageQualityConfirmed') === '1';
                $heroResult = $uploadValidator->validate($heroImageFile, UploadProfile::HeroImage, qualityWarningsConfirmed: $heroQualityConfirmed);

                if (!$heroResult->isValid && $heroResult->error !== null) {
                    // Fatal — a real UploadValidationError, never overridable.
                    $this->addFlash('error', $translator->trans(
                        $this->heroImageErrorKey($heroResult->error),
                        $this->heroImageErrorParams($heroResult->error),
                        domain: 'admin_settings'
                    ));
                    return $this->redirectToRoute('admin_settings');
                }

                if (!$heroResult->isValid) {
                    // error === null here: quality warnings only, not yet
                    // confirmed. Nothing is saved — not even the other
                    // fields in this same submission, same as any other
                    // early-return above — the owner re-submits (ticking
                    // "upload anyway", see settings.html.twig) to proceed.
                    foreach ($heroResult->warnings as $warning) {
                        $this->addFlash('warning', $translator->trans($this->heroImageWarningKey($warning), $this->heroImageWarningParams($heroResult), 'admin_settings'));
                    }
                    return $this->redirectToRoute('admin_settings');
                }
            }

            // Both valid: move them. If the second move fails, the first new
            // file is removed again, so the disk ends up as it started.
            $uploads = [];
            if ($logoFile) {
                $uploads[] = ['file' => $logoFile, 'dir' => $this->getParameter('app.uploads_dir') . '/logos', 'name' => $logoResult->safeFilename, 'old' => $restaurant->getLogo(), 'set' => $restaurant->setLogo(...), 'errorKey' => 'flash.logo_upload_error'];
            }
            if ($heroImageFile) {
                $uploads[] = ['file' => $heroImageFile, 'dir' => $this->getParameter('app.uploads_dir') . '/heroes', 'name' => $heroResult->safeFilename, 'old' => $restaurant->getHeroImage(), 'set' => $restaurant->setHeroImage(...), 'errorKey' => 'flash.hero_image_upload_error'];
            }
            $moved = [];
            $oldFilesToDelete = [];
            foreach ($uploads as $upload) {
                if (!is_dir($upload['dir'])) {
                    mkdir($upload['dir'], 0755, true);
                }
                try {
                    $upload['file']->move($upload['dir'], $upload['name']);
                } catch (FileException $e) {
                    foreach ($moved as $path) {
                        if (is_file($path)) {
                            unlink($path);
                        }
                    }
                    $this->addFlash('error', $translator->trans($upload['errorKey'], domain: 'admin_settings'));
                    return $this->redirectToRoute('admin_settings');
                }
                $moved[] = $upload['dir'] . '/' . $upload['name'];
                ($upload['set'])($upload['name']);
                if ($upload['old']) {
                    $oldFilesToDelete[] = $upload['dir'] . '/' . $upload['old'];
                }
            }

            // Saved — informative hero warnings (too narrow for a large
            // screen) are only reported now; the ones that needed
            // confirmation were already shown before this submission.
            foreach ($heroResult?->warnings ?? [] as $warning) {
                if (!$warning->requiresConfirmation()) {
                    $this->addFlash('warning', $translator->trans($this->heroImageWarningKey($warning), $this->heroImageWarningParams($heroResult), 'admin_settings'));
                }
            }

            // Handle logo removal
            if ($saves('appearance') && $request->request->get('removeLogoFlag') === '1') {
                $restaurant->setLogo(null);
            }

            // Handle hero image removal
            if ($saves('appearance') && $request->request->get('removeHeroImageFlag') === '1') {
                $restaurant->setHeroImage(null);
            }

            // name/tagline/logo/heroImage/currency/defaultLanguage
            // (and the contact details, for the menu footer) appear on the
            // public menu; adminLocale is the only field here
            // that doesn't. One flush covers all of them, so bump
            // unconditionally rather than trying to detect which fields
            // actually changed.
            $restaurant->bumpMenuContentVersion();
            $em->flush();

            // The replaced files are orphaned on disk only now that the new
            // names are stored.
            foreach ($oldFilesToDelete as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $this->addFlash('success', $translator->trans($section ? 'flash.section_saved' : 'flash.saved', domain: 'admin_settings'));

            // Another section's own button: a logo / hero image picked
            // meanwhile was ignored — say so in Apariencia, and land there
            // so it's actually seen.
            $discarded = $saves('appearance') ? null : $this->discardedUploads($request, $section);
            if ($discarded !== null) {
                $this->addFlash('settings_upload_discarded', $discarded);
                $section = 'appearance';
            }

            // Back to the section just saved, not the top of a long page.
            return $this->redirectToRoute('admin_settings', $section ? ['_fragment' => 'section-' . $section] : []);
        }

        return $this->render('admin/settings.html.twig', [
            'restaurant'            => $restaurant,
            'languages'             => $languages,
            'currencies'            => $currencies,
            'adminLocales'          => $adminLocaleResolver->getLocales(),
            'heroImageMinDimension' => UploadValidator::minDimension(UploadProfile::HeroImage),
            'heroImageRecommendedWidth' => UploadValidator::recommendedWidth(UploadProfile::HeroImage),
            'heroImageMaxDimension' => UploadValidator::maxDimension(UploadProfile::HeroImage),
            'heroImageMaxMegabytes' => UploadValidator::maxSizeMegabytes(UploadProfile::HeroImage),
            'errors'                => [],
            'discardedUploads'      => null,
            'old'                   => null,
        ]);
    }

    /**
     * A logo / hero image sent with this submission that won't be stored:
     * either the form came back with errors (a browser can't refill a file
     * input) or it was another section's own button (only Apariencia's, or
     * the save-everything one, stores files). Null when nothing was sent,
     * or when called on a successful save that did store them.
     *
     * @return array{key: string, viaAppearanceButton: bool}|null
     */
    private function discardedUploads(Request $request, ?string $section): ?array
    {
        $logo = $request->files->get('logo') !== null;
        $hero = $request->files->get('heroImage') !== null;
        $key = match (true) {
            $logo && $hero => 'hint.upload_discarded_both',
            $logo => 'hint.upload_discarded_logo',
            $hero => 'hint.upload_discarded_hero_image',
            default => null,
        };

        return $key === null ? null : [
            'key' => $key,
            'viaAppearanceButton' => $section !== null && $section !== 'appearance',
        ];
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
            UploadQualityWarning::WidthBelowRecommended => 'flash.hero_image_warning_width',
        };
    }

    /**
     * The photo's real size plus the profile's minimum and recommended
     * width, for every hero warning — each message picks the ones it
     * mentions. The numbers never live in the translation strings.
     */
    private function heroImageWarningParams(UploadValidationResult $result): array
    {
        return [
            '%width%'       => $result->width,
            '%height%'      => $result->height,
            '%min%'         => UploadValidator::minDimension(UploadProfile::HeroImage),
            '%recommended%' => UploadValidator::recommendedWidth(UploadProfile::HeroImage),
        ];
    }
}
