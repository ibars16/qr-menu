<?php

namespace App\Controller\Admin;

use App\Entity\Restaurant;
use App\Service\MenuLanguageChanger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Convirtiendo tu carta…": the screen a change of the menu's main language
 * (case b, see MenuLanguageChanger) lands on. Like the public menu's loading
 * screen, the browser does the waiting: it POSTs step() one batch at a time
 * until done — no queue, no cron. Closing the window just leaves
 * Restaurant::$languageChangeTo set; Configuración offers "Continuar".
 */
#[Route('/admin/idioma', name: 'admin_language_change_')]
#[IsGranted('ROLE_OWNER')]
class LanguageChangeController extends AbstractController
{
    public function __construct(
        private readonly MenuLanguageChanger $changer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/convirtiendo', name: 'screen', methods: ['GET'])]
    public function screen(): Response
    {
        $restaurant = $this->restaurant();
        if ($restaurant->getLanguageChangeTo() === null) {
            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/language_change.html.twig', [
            'language' => self::languageName($restaurant->getLanguageChangeTo(), $restaurant->getAdminLocale()),
        ]);
    }

    #[Route('/convertir', name: 'step', methods: ['POST'])]
    public function step(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_language_change', (string) $request->request->get('_token'))) {
            return $this->json(['error' => true], Response::HTTP_FORBIDDEN);
        }
        $restaurant = $this->restaurant();
        $to = $restaurant->getLanguageChangeTo();

        $result = $this->changer->step($restaurant);
        if ($result['done'] && $to !== null) {
            $this->addFlash('success', self::doneMessage($this->translator, $to, $restaurant->getAdminLocale()));
        }

        return $this->json($result + ['next' => $this->generateUrl('admin_settings', ['_fragment' => 'section-language'])]);
    }

    public static function doneMessage(TranslatorInterface $translator, string $language, string $adminLocale): string
    {
        return $translator->trans('language_change.done', ['%language%' => self::languageName($language, $adminLocale)], 'admin_settings');
    }

    /** "inglés", "Englisch"… in the panel's language. */
    public static function languageName(string $code, string $adminLocale): string
    {
        try {
            return Languages::getName($code, $adminLocale);
        } catch (\Throwable) {
            return $code;
        }
    }

    private function restaurant(): Restaurant
    {
        return $this->getUser()?->getRestaurant() ?? throw $this->createAccessDeniedException();
    }
}
