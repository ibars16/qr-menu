<?php

namespace App\Controller\Admin;

use App\Service\AdminLocaleResolver;
use App\Service\AiAccess;
use App\Service\ContactDetailsNormalizer;
use App\Service\MenuLanguageChanger;
use App\Service\OwnerTextTranslationPruner;
use App\Service\Upload\UploadProfile;
use App\Service\Upload\UploadQualityWarning;
use App\Service\Upload\UploadValidationError;
use App\Service\Upload\UploadValidationResult;
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

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_OWNER')]
class SettingsController extends AbstractController
{
    /** Values of the per-section save buttons (name="section"). */
    private const SECTIONS = ['info', 'contact', 'social', 'hours', 'language'];

    #[Route('/settings', name: 'settings')]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        AdminLocaleResolver $adminLocaleResolver,
        TranslatorInterface $translator,
        ContactDetailsNormalizer $contactNormalizer,
        MenuLanguageChanger $languageChanger,
        OwnerTextTranslationPruner $ownerTextPruner,
        AiAccess $aiAccess,
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
            'languageChangeName'    => $restaurant->getLanguageChangeTo() !== null ? LanguageChangeController::languageName($restaurant->getLanguageChangeTo(), $restaurant->getAdminLocale()) : null,
                    'heroImageMinDimension' => UploadValidator::minDimension(UploadProfile::HeroImage),
                    'heroImageRecommendedWidth' => UploadValidator::recommendedWidth(UploadProfile::HeroImage),
                    'heroImageMaxDimension' => UploadValidator::maxDimension(UploadProfile::HeroImage),
                    'heroImageMaxMegabytes' => UploadValidator::maxSizeMegabytes(UploadProfile::HeroImage),
                    'errors'                => $errors,
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
            // A new main language for the menu isn't just stored: its texts
            // are converted (MenuLanguageChanger), after the flush below.
            // While a change is half done the field is locked.
            $languageChange = null;
            $languageAiBlocked = false;
            if ($saves('language')) {
                $restaurant->setCurrency($currency);
                $restaurant->setAdminLocale($adminLocaleResolver->resolve($adminLocale));
                if ($restaurant->getLanguageChangeTo() === null && $language !== $restaurant->getDefaultLanguage() && isset($languages[$language])) {
                    // Converting the texts is AI work (AiAccess): not while
                    // the restaurant is off; the rest of the section saves.
                    if ($aiAccess->allows($restaurant, AiAccess::LANGUAGE_CHANGE)) {
                        $languageChange = $language;
                    } else {
                        $languageAiBlocked = true;
                    }
                }
            }

            // name/tagline/currency/defaultLanguage
            // (and the contact details, for the menu footer) appear on the
            // public menu; adminLocale is the only field here
            // that doesn't. One flush covers all of them, so bump
            // unconditionally rather than trying to detect which fields
            // actually changed.
            $restaurant->bumpMenuContentVersion();
            $em->flush();
            // A rewritten or removed tagline leaves its translations unused
            // (never mid-language-change: see OwnerTextTranslationPruner).
            if ($saves('info')) {
                $ownerTextPruner->prune($restaurant);
            }

            $languageResult = $languageChange !== null ? $languageChanger->start($restaurant, $languageChange) : null;
            if ($languageResult === MenuLanguageChanger::CONVERTING) {
                return $this->redirectToRoute('admin_language_change_screen');
            }
            if ($languageResult === MenuLanguageChanger::DONE) {
                $this->addFlash('success', LanguageChangeController::doneMessage($translator, $languageChange, $restaurant->getAdminLocale()));
            } else {
                $this->addFlash('success', $translator->trans($section ? 'flash.section_saved' : 'flash.saved', domain: 'admin_settings'));
            }
            if ($languageResult === MenuLanguageChanger::BLOCKED_IMPORT) {
                $this->addFlash('error', $translator->trans('language_change.blocked_import', domain: 'admin_settings'));
            }
            if ($languageAiBlocked) {
                $this->addFlash('error', $translator->trans('ai_blocked', domain: 'admin_base'));
            }

            // Back to the section just saved, not the top of a long page.
            return $this->redirectToRoute('admin_settings', $section ? ['_fragment' => 'section-' . $section] : []);
        }

        return $this->render('admin/settings.html.twig', [
            'restaurant'            => $restaurant,
            'languages'             => $languages,
            'currencies'            => $currencies,
            'adminLocales'          => $adminLocaleResolver->getLocales(),
            'languageChangeName'    => $restaurant->getLanguageChangeTo() !== null ? LanguageChangeController::languageName($restaurant->getLanguageChangeTo(), $restaurant->getAdminLocale()) : null,
            'heroImageMinDimension' => UploadValidator::minDimension(UploadProfile::HeroImage),
            'heroImageRecommendedWidth' => UploadValidator::recommendedWidth(UploadProfile::HeroImage),
            'heroImageMaxDimension' => UploadValidator::maxDimension(UploadProfile::HeroImage),
            'heroImageMaxMegabytes' => UploadValidator::maxSizeMegabytes(UploadProfile::HeroImage),
            'errors'                => [],
            'old'                   => null,
        ]);
    }

    /**
     * Logo / hero image of Apariencia, saved the moment it's picked or
     * removed (settings.html.twig posts here with fetch) — no "Guardar".
     * One image per request, so nothing half-applied can happen; the old
     * file is deleted only once the new name is flushed.
     *
     * JSON: {ok: true, url, message, notices} — url null after a removal,
     * notices = informative hero warnings (saved anyway);
     * {ok: false, error} on a rejected file;
     * {ok: false, needsConfirmation: true, warnings} when the hero needs
     * "Subir de todas formas" (re-posted with qualityConfirmed=1).
     */
    #[Route('/settings/image/{kind}', name: 'settings_image', requirements: ['kind' => 'logo|hero'], methods: ['POST'])]
    public function image(
        string $kind,
        Request $request,
        EntityManagerInterface $em,
        UploadValidator $uploadValidator,
        TranslatorInterface $translator,
    ): JsonResponse {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException();
        }
        $t = static fn (string $key, array $params = []): string => $translator->trans($key, $params, 'admin_settings');

        if (!$this->isCsrfTokenValid('admin_settings', (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'error' => $t('flash.csrf_invalid')], Response::HTTP_FORBIDDEN);
        }

        $isLogo = $kind === 'logo';
        $subdir = $isLogo ? 'logos' : 'heroes';
        $dir = $this->getParameter('app.uploads_dir') . '/' . $subdir;
        $old = $isLogo ? $restaurant->getLogo() : $restaurant->getHeroImage();
        $set = $isLogo ? $restaurant->setLogo(...) : $restaurant->setHeroImage(...);

        if ($request->request->get('action') === 'remove') {
            $set(null);
            $restaurant->bumpMenuContentVersion();
            $em->flush();
            $this->deleteUpload($dir, $old);

            return $this->json(['ok' => true, 'url' => null, 'message' => $t($isLogo ? 'toast.logo_removed' : 'toast.hero_image_removed'), 'notices' => []]);
        }

        $uploadErrorKey = $isLogo ? 'flash.logo_upload_error' : 'flash.hero_image_upload_error';
        $file = $request->files->get('image');
        if (!$file instanceof UploadedFile) {
            return $this->json(['ok' => false, 'error' => $t($uploadErrorKey)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($isLogo) {
            $result = $uploadValidator->validate($file, UploadProfile::Logo);
            if (!$result->isValid) {
                return $this->json(['ok' => false, 'error' => $t(self::logoErrorKey($result->error))], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            // Only ever lets a QUALITY warning (small/portrait) through —
            // see UploadValidator::validate()'s own docblock.
            $result = $uploadValidator->validate($file, UploadProfile::HeroImage, qualityWarningsConfirmed: $request->request->get('qualityConfirmed') === '1');
            if (!$result->isValid && $result->error !== null) {
                return $this->json(['ok' => false, 'error' => $t(self::heroImageErrorKey($result->error), self::heroImageErrorParams($result->error))], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            if (!$result->isValid) {
                return $this->json([
                    'ok' => false,
                    'needsConfirmation' => true,
                    'warnings' => array_map(fn (UploadQualityWarning $w): string => $t($this->heroImageWarningKey($w), $this->heroImageWarningParams($result)), $result->warnings),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        try {
            $file->move($dir, $result->safeFilename);
        } catch (FileException) {
            return $this->json(['ok' => false, 'error' => $t($uploadErrorKey)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $set($result->safeFilename);
        $restaurant->bumpMenuContentVersion();
        $em->flush();
        $this->deleteUpload($dir, $old);

        // Informative hero warnings (too narrow for a large screen): the
        // photo is stored, the owner is just told.
        $notices = [];
        foreach ($isLogo ? [] : $result->warnings as $warning) {
            if (!$warning->requiresConfirmation()) {
                $notices[] = $t($this->heroImageWarningKey($warning), $this->heroImageWarningParams($result));
            }
        }

        return $this->json([
            'ok'      => true,
            'url'     => $request->getBasePath() . '/uploads/' . $subdir . '/' . $result->safeFilename,
            'message' => $t($isLogo ? 'toast.logo_saved' : 'toast.hero_image_saved'),
            'notices' => $notices,
        ]);
    }

    private function deleteUpload(string $dir, ?string $filename): void
    {
        if ($filename && is_file($dir . '/' . $filename)) {
            unlink($dir . '/' . $filename);
        }
    }

    public static function logoErrorKey(UploadValidationError $error): string
    {
        return match ($error) {
            UploadValidationError::UnsupportedType => 'flash.logo_unsupported_type',
            UploadValidationError::TooLarge => 'flash.logo_too_large',
            UploadValidationError::DimensionsTooSmall => 'flash.logo_dimensions_too_small',
            UploadValidationError::DimensionsTooLarge => 'flash.logo_dimensions_too_large',
            UploadValidationError::InvalidFile, UploadValidationError::Rejected => 'flash.logo_upload_error',
        };
    }

    public static function heroImageErrorKey(UploadValidationError $error): string
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
    public static function heroImageErrorParams(UploadValidationError $error): array
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
