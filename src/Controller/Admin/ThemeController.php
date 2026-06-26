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
    public const THEMES = [
        'classic' => [
            'name'  => 'Clásico cálido',
            'desc'  => 'Fondo claro, filas horizontales. Muy legible y familiar.',
            'icon'  => '☀️',
        ],
        'glass' => [
            'name'  => 'Glassmorphism',
            'desc'  => 'Oscuro con acento morado. Moderno y premium.',
            'icon'  => '💎',
        ],
        'bold' => [
            'name'  => 'Lista compacta',
            'desc'  => 'Filas numeradas. El más rápido de escanear.',
            'icon'  => '⚡',
        ],
        'grid' => [
            'name'  => 'Cuadrícula',
            'desc'  => 'Dos columnas con imagen grande. Visual e impactante.',
            'icon'  => '⊞',
        ],
    ];

    #[Route('/theme', name: 'theme')]
    public function index(): Response
    {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException();
        }

        // Build preview URL using first table if available
        $previewBase = null;
        if ($restaurant->getTables()->first()) {
            $previewBase = $this->generateUrl('menu_show', [
                'slug'     => $restaurant->getSlug(),
                'qrToken'  => $restaurant->getTables()->first()->getQrToken(),
            ]);
        }

        return $this->render('admin/theme.html.twig', [
            'restaurant'  => $restaurant,
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

        $theme = $request->toArray()['theme'] ?? 'classic';

        if (!array_key_exists($theme, self::THEMES)) {
            return $this->json(['error' => 'Invalid theme'], 400);
        }

        $restaurant->setTheme($theme);
        $em->flush();

        return $this->json([
            'ok'    => true,
            'theme' => $theme,
            'name'  => self::THEMES[$theme]['name'],
        ]);
    }
}
