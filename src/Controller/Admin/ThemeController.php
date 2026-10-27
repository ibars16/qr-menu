<?php

namespace App\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_STAFF')]
class ThemeController extends AbstractController
{
    // 'name' and 'desc' are NOT stored here — they're translated per-locale in
    // translations/admin_theme.*.yaml under keys layout.<key>.name/.desc and
    // theme.<key>.name/.desc, looked up in the template by array key. The
    // thumbnail colours aren't either: swatches() reads them from each
    // theme's own tokens (templates/menu/themes/<key>/vars.html.twig).
    public const LAYOUTS = [
        'standard' => [
            'icon' => '▤',
        ],
    ];

    public const THEMES = [
        'maison' => [
            'icon' => '🍷',
        ],
        'classic-dark' => [
            'icon' => '🌑',
        ],
        'glass' => [
            'icon' => '💎',
        ],
        'ocean' => [
            'icon' => '🌊',
        ],
        'noir' => [
            'icon' => '✦',
        ],
        'forest' => [
            'icon' => '🌿',
        ],
    ];

    #[Route('/theme', name: 'theme')]
    public function index(): Response
    {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException();
        }

        $previewBase = $this->generateUrl('menu_show', ['slug' => $restaurant->getSlug()]);

        // A stored theme that no longer exists (classic-warm, terra) renders
        // as maison on the public menu, so that's the card shown as active.
        $currentTheme = array_key_exists($restaurant->getTheme(), self::THEMES) ? $restaurant->getTheme() : 'maison';

        return $this->render('admin/theme.html.twig', [
            'restaurant'  => $restaurant,
            'currentTheme' => $currentTheme,
            'layouts'     => self::LAYOUTS,
            'themes'      => $this->swatches(),
            'previewBase' => $previewBase,
        ]);
    }

    /**
     * THEMES plus each theme's thumbnail colours, read from the tokens its
     * public-menu vars.html.twig defines, so the thumbnail always matches
     * the menu: page (--bg), header and cards (--surface), card border
     * (--b2), photo area (--surface-2), text bars (--fg), active tab and
     * price (--accent). Card radius is the shared structure's --card-radius.
     *
     * @return array<string, array<string, string>>
     */
    private function swatches(): array
    {
        $tokens = ['preview_bg' => 'bg', 'preview_surface' => 'surface', 'preview_border' => 'b2',
                   'preview_img' => 'surface-2', 'preview_fg' => 'fg', 'preview_accent' => 'accent'];
        $dir = $this->getParameter('kernel.project_dir') . '/templates/menu/themes/';
        $themes = [];
        foreach (self::THEMES as $key => $theme) {
            $css = (string) file_get_contents($dir . $key . '/vars.html.twig');
            foreach ($tokens as $field => $token) {
                $theme[$field] = preg_match('/--' . preg_quote($token, '/') . ':\s*([^;]+);/', $css, $m) ? trim($m[1]) : 'transparent';
            }
            $theme['preview_radius'] = '6px';
            $themes[$key] = $theme;
        }

        return $themes;
    }

    #[Route('/theme/apply', name: 'theme_apply', methods: ['POST'])]
    public function apply(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            return $this->json(['error' => 'No restaurant'], 403);
        }

        $data   = $request->toArray();
        $layout = $data['layout'] ?? null;
        $theme  = $data['theme']  ?? null;

        if ($layout && array_key_exists($layout, self::LAYOUTS)) {
            $restaurant->setLayout($layout);
        }
        if ($theme && array_key_exists($theme, self::THEMES)) {
            $restaurant->setTheme($theme);
        }

        if (!$layout && !$theme) {
            return $this->json(['error' => 'Nothing to update'], 400);
        }

        $em->flush();

        return $this->json([
            'ok'     => true,
            'layout' => $restaurant->getLayout(),
            'theme'  => $restaurant->getTheme(),
        ]);
    }
}
