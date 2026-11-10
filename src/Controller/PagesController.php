<?php

namespace App\Controller;

use App\Service\PublicSiteLocaleResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Static public content — no auth, no redirect for logged-in users, unlike
 * HomeController's marketing role. In the site's language
 * (PublicSiteLocaleResolver, like the home): each page's text is one
 * template per language, templates/pages/{page}/{locale}.html.twig.
 */
class PagesController extends AbstractController
{
    public function __construct(private readonly PublicSiteLocaleResolver $localeResolver)
    {
    }

    #[Route('/sobre-nosotros', name: 'app_about')]
    public function about(Request $request): Response
    {
        return $this->page($request, 'about');
    }

    #[Route('/privacidad', name: 'app_privacy')]
    public function privacy(Request $request): Response
    {
        return $this->page($request, 'privacy');
    }

    #[Route('/terminos', name: 'app_terms')]
    public function terms(Request $request): Response
    {
        return $this->page($request, 'terms');
    }

    #[Route('/cookies', name: 'app_cookies')]
    public function cookies(Request $request): Response
    {
        return $this->page($request, 'cookies');
    }

    private function page(Request $request, string $page): Response
    {
        return $this->localeResolver->remember($request, $this->render('pages/page.html.twig', [
            'page'    => $page,
            'locale'  => $this->localeResolver->resolveFromRequest($request),
            'locales' => $this->localeResolver->getLocales(),
        ]));
    }
}
