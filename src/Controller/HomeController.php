<?php

namespace App\Controller;

use App\Service\PublicSiteLocaleResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, PublicSiteLocaleResolver $localeResolver): Response
    {
        // Shown logged in too (its nav then links to the panel): the logo
        // and "Inicio" links of the public pages all lead here.
        $locale = $localeResolver->resolveFromRequest($request);

        return $this->render('home/index.html.twig', [
            'locale'  => $locale,
            'locales' => $localeResolver->getLocales(),
        ]);
    }
}
