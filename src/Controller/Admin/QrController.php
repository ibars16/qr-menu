<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class QrController extends AbstractController
{
    #[Route('/qr', name: 'qr')]
    public function index(): Response
    {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException();
        }

        $menuUrl = $this->generateUrl(
            'menu_show',
            ['slug' => $restaurant->getSlug()],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->render('admin/qr.html.twig', [
            'restaurant' => $restaurant,
            'menuUrl'    => $menuUrl,
        ]);
    }
}
