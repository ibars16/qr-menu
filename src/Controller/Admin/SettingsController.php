<?php

namespace App\Controller\Admin;

use App\Entity\Restaurant;
use App\Service\AdminLocaleResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'settings')]
    public function settings(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        AdminLocaleResolver $adminLocaleResolver,
        TranslatorInterface $translator,
    ): Response {
        $restaurant = $this->getUser()->getRestaurant();
        if (!$restaurant) {
            throw $this->createAccessDeniedException();
        }

        $languages  = require $this->getParameter('kernel.project_dir') . '/config/languages.php';
        $currencies = require $this->getParameter('kernel.project_dir') . '/config/currencies.php';

        if ($request->isMethod('POST')) {
            $name        = trim($request->request->get('name', ''));
            $color       = $request->request->get('primaryColor', '#C1440E');
            $currency    = $request->request->get('currency', 'EUR');
            $language    = $request->request->get('defaultLanguage', 'es');
            $adminLocale = $request->request->get('adminLocale', $adminLocaleResolver->getDefaultLocale());

            if (!$name) {
                $this->addFlash('error', $translator->trans('flash.name_required', domain: 'admin_settings'));
                return $this->redirectToRoute('admin_settings');
            }

            $restaurant->setName($name);
            $restaurant->setPrimaryColor($color);
            $restaurant->setCurrency($currency);
            $restaurant->setDefaultLanguage($language);
            $restaurant->setAdminLocale($adminLocaleResolver->resolve($adminLocale));

            // Handle logo upload
            $logoFile = $request->files->get('logo');
            if ($logoFile) {
                $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename     = $slugger->slug($originalFilename);
                $newFilename      = $safeFilename . '-' . uniqid() . '.' . $logoFile->guessExtension();

                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/logos';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                try {
                    $logoFile->move($uploadDir, $newFilename);
                    $restaurant->setLogo($newFilename);
                } catch (FileException $e) {
                    $this->addFlash('error', $translator->trans('flash.logo_upload_error', domain: 'admin_settings'));
                    return $this->redirectToRoute('admin_settings');
                }
            }

            // Handle logo removal
            if ($request->request->get('removeLogo') === '1') {
                $restaurant->setLogo(null);
            }

            $em->flush();
            $this->addFlash('success', $translator->trans('flash.saved', domain: 'admin_settings'));
            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'restaurant'   => $restaurant,
            'languages'    => $languages,
            'currencies'   => $currencies,
            'adminLocales' => $adminLocaleResolver->getLocales(),
        ]);
    }
}
