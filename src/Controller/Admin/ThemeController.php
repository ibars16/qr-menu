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
#[IsGranted('ROLE_USER')]
class ThemeController extends AbstractController
{
    // 'name' and 'desc' are NOT stored here — they're translated per-locale in
    // translations/admin_theme.*.yaml under keys layout.<key>.name/.desc and
    // theme.<key>.name/.desc, looked up in the template by array key.
    public const LAYOUTS = [
        'standard' => [
            'icon' => '▤',
        ],
        'compact' => [
            'icon' => '≡',
        ],
        'grid' => [
            'icon' => '⊞',
        ],
    ];

    public const THEMES = [
        'classic-dark' => [
            'icon'             => '🌑',
            'preview_bg'       => '#0a0a0a',
            'preview_accent'   => '#C1440E',
            'preview_surface'  => '#161616',
            'preview_border'   => '#282828',
            'preview_fg'       => '#ffffff',
            'preview_radius'   => '10px',
        ],
        'classic-warm' => [
            'icon'             => '☀️',
            'preview_bg'       => '#faf7f2',
            'preview_accent'   => '#C1440E',
            'preview_surface'  => '#ffffff',
            'preview_border'   => '#e8e2d8',
            'preview_fg'       => '#1a1209',
            'preview_radius'   => '16px',
        ],
        'glass' => [
            'icon'             => '💎',
            'preview_bg'       => 'linear-gradient(135deg, #0d0320 0%, #120828 50%, #050c1a 100%)',
            'preview_accent'   => '#a78bfa',
            'preview_surface'  => 'rgba(255,255,255,0.08)',
            'preview_border'   => 'rgba(255,255,255,0.13)',
            'preview_fg'       => '#ffffff',
            'preview_radius'   => '14px',
        ],
        'ocean' => [
            'icon'             => '🌊',
            'preview_bg'       => '#f0f6ff',
            'preview_accent'   => '#2563eb',
            'preview_surface'  => '#ffffff',
            'preview_border'   => '#cce0f5',
            'preview_fg'       => '#0d1b2e',
            'preview_radius'   => '14px',
        ],
        'noir' => [
            'icon'             => '✦',
            'preview_bg'       => '#080808',
            'preview_accent'   => '#d4a847',
            'preview_surface'  => '#0e0e0c',
            'preview_border'   => '#201e18',
            'preview_fg'       => '#f0e8d4',
            'preview_radius'   => '7px',
        ],
        'forest' => [
            'icon'             => '🌿',
            'preview_bg'       => '#f0f4f0',
            'preview_accent'   => '#1c6030',
            'preview_surface'  => '#ffffff',
            'preview_border'   => '#c8dcc8',
            'preview_fg'       => '#152315',
            'preview_radius'   => '16px',
        ],
        'terra' => [
            'icon'             => '🏺',
            'preview_bg'       => '#faf3ec',
            'preview_accent'   => '#b83a18',
            'preview_surface'  => '#ffffff',
            'preview_border'   => '#e6cfbc',
            'preview_fg'       => '#281308',
            'preview_radius'   => '13px',
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

        return $this->render('admin/theme.html.twig', [
            'restaurant'  => $restaurant,
            'layouts'     => self::LAYOUTS,
            'themes'      => self::THEMES,
            'previewBase' => $previewBase,
        ]);
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
