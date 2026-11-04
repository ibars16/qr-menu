<?php

namespace App\Controller\Platform;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\Billing\StripeBilling;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The platform owner's own panel (ROLE_SUPER_ADMIN only, Spanish only):
 * every restaurant with its Restaurant::$paidUntil, every reseller with its
 * own, and the two manual billing actions for each. A reseller's
 * restaurants aren't billed on their own, so they get neither. "Registrar
 * pago" is refused while Stripe charges a card (a live subscription), so
 * days are never added twice; a reseller's resumes its restaurants' card
 * payments at once (StripeBilling::resumeResellerCollectionOrLog()). Nothing
 * here ever deletes anything — "Desactivar ya" only moves paidUntil to yesterday.
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

        // First owner per restaurant, first user per reseller. Queried from
        // the owning side rather than read off Restaurant::$users (the
        // inverse side, which isn't kept in sync in memory when a user is
        // linked).
        $owners = [];
        $resellerUsers = [];
        foreach ($em->getRepository(User::class)->findBy([], ['id' => 'ASC']) as $user) {
            $restaurantId = $user->getRestaurant()?->getId();
            if ($restaurantId !== null && !isset($owners[$restaurantId]) && in_array(User::ROLE_OWNER, $user->getRoles(), true)) {
                $owners[$restaurantId] = $user;
            }
            $resellerId = $user->getReseller()?->getId();
            if ($resellerId !== null && !isset($resellerUsers[$resellerId])) {
                $resellerUsers[$resellerId] = $user;
            }
        }

        // Per reseller: restaurants in use (what it pays for) and archived.
        $counts = [];
        foreach ($restaurants as $restaurant) {
            $resellerId = $restaurant->getReseller()?->getId();
            if ($resellerId !== null) {
                $counts[$resellerId] ??= ['active' => 0, 'archived' => 0];
                $counts[$resellerId][$restaurant->isArchived() ? 'archived' : 'active']++;
            }
        }
        $resellerRows = array_map(
            static fn (Reseller $reseller): array => [
                'reseller'    => $reseller,
                'user'        => $resellerUsers[$reseller->getId()] ?? null,
                'restaurants' => $counts[$reseller->getId()] ?? ['active' => 0, 'archived' => 0],
            ],
            $em->getRepository(Reseller::class)->findBy([], ['paidUntil' => 'ASC', 'name' => 'ASC']),
        );

        $rows = array_map(
            static fn (Restaurant $restaurant): array => ['restaurant' => $restaurant, 'owner' => $owners[$restaurant->getId()] ?? null],
            $restaurants,
        );

        // Summary cards — informative only. "Expiring soon" overlaps with
        // active/trial on purpose (today up to today + 7 days, not expired).
        // Only restaurants billed on their own.
        $summary = ['active' => 0, 'trial' => 0, 'expiringSoon' => 0, 'expired' => 0];
        foreach ($restaurants as $restaurant) {
            if ($restaurant->isBilledByReseller()) {
                continue;
            }
            if ($restaurant->isExpired()) {
                $summary['expired']++;
                continue;
            }
            $summary[$restaurant->getPlan() === null ? 'trial' : 'active']++;
            if ($restaurant->getDaysLeft() <= 7) {
                $summary['expiringSoon']++;
            }
        }

        return $this->render('platform/index.html.twig', ['rows' => $rows, 'resellerRows' => $resellerRows, 'summary' => $summary]);
    }

    #[Route('/restaurants/{id}/register-payment', name: 'register_payment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function registerPayment(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $restaurant = $this->ownBilledRestaurant($id, $em);

        return $this->registerPaymentFor($restaurant, $restaurant->getName(), 'platform-register-payment-' . $restaurant->getId(), $request, $em);
    }

    #[Route('/restaurants/{id}/deactivate', name: 'deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $restaurant = $this->ownBilledRestaurant($id, $em);

        return $this->deactivateFor($restaurant, $restaurant->getName(), 'platform-deactivate-' . $restaurant->getId(), $request, $em);
    }

    #[Route('/resellers/{id}/register-payment', name: 'reseller_register_payment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function registerResellerPayment(int $id, Request $request, EntityManagerInterface $em, StripeBilling $billing): Response
    {
        $reseller = $em->find(Reseller::class, $id) ?? throw $this->createNotFoundException();

        $response = $this->registerPaymentFor($reseller, $reseller->getName(), 'platform-reseller-register-payment-' . $reseller->getId(), $request, $em);
        $billing->resumeResellerCollectionOrLog($reseller);

        return $response;
    }

    #[Route('/resellers/{id}/deactivate', name: 'reseller_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivateReseller(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $reseller = $em->find(Reseller::class, $id) ?? throw $this->createNotFoundException();

        return $this->deactivateFor($reseller, $reseller->getName(), 'platform-reseller-deactivate-' . $reseller->getId(), $request, $em);
    }

    /** A reseller's restaurant has no billing of its own: its reseller pays. */
    private function ownBilledRestaurant(int $id, EntityManagerInterface $em): Restaurant
    {
        $restaurant = $em->find(Restaurant::class, $id) ?? throw $this->createNotFoundException();
        if ($restaurant->isBilledByReseller()) {
            throw new BadRequestHttpException('Lo paga su revendedor.');
        }

        return $restaurant;
    }

    private function registerPaymentFor(Restaurant|Reseller $customer, string $name, string $tokenId, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $plan = $request->request->get('plan');
        if (!isset(Restaurant::PLAN_MONTHS[$plan])) {
            throw new BadRequestHttpException('Plan no válido.');
        }
        if ($customer->hasLiveSubscription()) {
            throw new BadRequestHttpException('Paga con tarjeta: los pagos los registra Stripe.');
        }

        $customer->markPaid($plan);
        $em->flush();

        $this->addFlash('success', sprintf('Pago registrado: %s queda activo hasta el %s.', $name, $customer->getPaidUntil()->format('d/m/Y')));

        return $this->redirectToRoute('platform_index');
    }

    private function deactivateFor(Restaurant|Reseller $customer, string $name, string $tokenId, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid($tokenId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF no válido.');
        }

        $customer->deactivateNow();
        $em->flush();

        $this->addFlash('success', sprintf('%s: desactivado.', $name));

        return $this->redirectToRoute('platform_index');
    }
}
