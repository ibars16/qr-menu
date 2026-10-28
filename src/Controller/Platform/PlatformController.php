<?php

namespace App\Controller\Platform;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The platform owner's own panel (ROLE_SUPER_ADMIN only, Spanish only):
 * every restaurant with its Restaurant::$paidUntil, and the two manual
 * billing actions. Nothing here ever deletes anything — "Desactivar ya"
 * only moves paidUntil to yesterday.
 */
#[Route('/platform', name: 'platform_')]
#[IsGranted(User::ROLE_SUPER_ADMIN)]
class PlatformController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        // Expired and soonest-to-expire first.
        $restaurants = $em->getRepository(Restaurant::class)->findBy([], ['paidUntil' => 'ASC', 'name' => 'ASC']);

        // First owner per restaurant. Queried from the owning side rather
        // than read off Restaurant::$users (the inverse side, which isn't
        // kept in sync in memory when a user is linked).
        $owners = [];
        foreach ($em->getRepository(User::class)->findBy([], ['id' => 'ASC']) as $user) {
            $restaurantId = $user->getRestaurant()?->getId();
            if ($restaurantId !== null && !isset($owners[$restaurantId]) && in_array(User::ROLE_OWNER, $user->getRoles(), true)) {
                $owners[$restaurantId] = $user;
            }
        }

        $rows = array_map(
            static fn (Restaurant $restaurant): array => ['restaurant' => $restaurant, 'owner' => $owners[$restaurant->getId()] ?? null],
            $restaurants,
        );

        // Summary cards — informative only. "Expiring soon" overlaps with
        // active/trial on purpose (today up to today + 7 days, not expired).
        $summary = ['active' => 0, 'trial' => 0, 'expiringSoon' => 0, 'expired' => 0];
        foreach ($restaurants as $restaurant) {
            if ($restaurant->isExpired()) {
                $summary['expired']++;
                continue;
            }
            $summary[$restaurant->getPlan() === null ? 'trial' : 'active']++;
            if ($restaurant->getDaysLeft() <= 7) {
                $summary['expiringSoon']++;
            }
        }

        return $this->render('platform/index.html.twig', ['rows' => $rows, 'summary' => $summary]);
    }

    #[Route('/restaurants/{id}/register-payment', name: 'register_payment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function registerPayment(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $restaurant = $em->find(Restaurant::class, $id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('platform-register-payment-' . $restaurant->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $plan = $request->request->get('plan');
        if (!isset(Restaurant::PLAN_MONTHS[$plan])) {
            throw new BadRequestHttpException('Plan no válido.');
        }

        $restaurant->markPaid($plan);
        $em->flush();

        $this->addFlash('success', sprintf('Pago registrado: %s queda activo hasta el %s.', $restaurant->getName(), $restaurant->getPaidUntil()->format('d/m/Y')));

        return $this->redirectToRoute('platform_index');
    }

    #[Route('/restaurants/{id}/deactivate', name: 'deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $restaurant = $em->find(Restaurant::class, $id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('platform-deactivate-' . $restaurant->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $restaurant->deactivateNow();
        $em->flush();

        $this->addFlash('success', sprintf('%s: desactivado.', $restaurant->getName()));

        return $this->redirectToRoute('platform_index');
    }
}
