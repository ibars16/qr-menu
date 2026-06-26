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
    public const LAYOUTS = [
        'standard' => [
            'name' => 'Estándar',
            'desc' => 'Tarjetas horizontales con imagen a la izquierda. El más versátil.',
            'icon' => '▤',
        ],
        'compact' => [
            'name' => 'Lista compacta',
            'desc' => 'Filas numeradas, rápidas de escanear. Ideal para cartas extensas.',
            'icon' => '≡',
        ],
        'grid' => [
            'name' => 'Cuadrícula',
            'desc' => 'Tarjetas verticales con imagen grande. Muy visual e impactante.',
            'icon' => '⊞',
        ],
    ];

    public const THEMES = [
        'classic-dark' => [
            'name'             => 'Clásico oscuro',
            'desc'             => 'Fondo negro, acento del color de tu marca. Elegante y sofisticado.',
            'icon'             => '🌑',
            'preview_bg'       => '#0a0a0a',
            'preview_accent'   => '#C1440E',
            'preview_surface'  => '#161616',
            'preview_border'   => '#242424',
            'preview_fg'       => '#ffffff',
        ],
        'classic-warm' => [
            'name'             => 'Clásico cálido',
            'desc'             => 'Fondo crema, tonos cálidos. Acogedor y cercano.',
            'icon'             => '☀️',
            'preview_bg'       => '#faf7f2',
            'preview_accent'   => '#C1440E',
            'preview_surface'  => '#ffffff',
            'preview_border'   => '#e8e2d8',
            'preview_fg'       => '#1a1209',
        ],
        'glass' => [
            'name'             => 'Glassmorphism',
            'desc'             => 'Degradado oscuro con efecto cristal y acento morado. Moderno y premium.',
            'icon'             => '💎',
            'preview_bg'       => '#120820',
            'preview_accent'   => '#a78bfa',
            'preview_surface'  => 'rgba(255,255,255,0.07)',
            'preview_border'   => 'rgba(255,255,255,0.12)',
            'preview_fg'       => '#ffffff',
        ],
        'ocean' => [
            'name'             => 'Océano',
            'desc'             => 'Azul claro, limpio y fresco. Ideal para cocina del mar.',
            'icon'             => '🌊',
            'preview_bg'       => '#f0f6ff',
            'preview_accent'   => '#2563eb',
            'preview_surface'  => '#ffffff',
            'preview_border'   => '#dce8f5',
            'preview_fg'       => '#0d1b2e',
        ],
        'noir' => [
            'name'             => 'Noir',
            'desc'             => 'Negro profundo con detalles dorados. Lujo y alta gastronomía.',
            'icon'             => '✦',
            'preview_bg'       => '#080808',
            'preview_accent'   => '#d4a847',
            'preview_surface'  => '#0e0e0c',
            'preview_border'   => '#1e1c18',
            'preview_fg'       => '#f0e8d4',
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
