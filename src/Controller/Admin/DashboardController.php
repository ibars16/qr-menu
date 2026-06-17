<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    // Redirige /admin → /admin/menu
    #[Route('', name: 'dashboard')]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_menu');
    }

    // Estas rutas las gestiona MenuAdminController:
    // admin_menu, admin_category_*, admin_product_*
    // admin_ingredients_list, admin_reorder_*

    #[Route('/tags', name: 'tags')]
    public function tags(): Response
    {
        return $this->render('admin/tags.html.twig');
    }

    #[Route('/tables', name: 'tables')]
    public function tables(): Response
    {
        return $this->render('admin/tables.html.twig');
    }

    #[Route('/settings', name: 'settings')]
    public function settings(): Response
    {
        return $this->render('admin/settings.html.twig');
    }
}
