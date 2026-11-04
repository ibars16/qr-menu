<?php

namespace App\Controller\Partners;

use App\Entity\Reseller;
use App\Entity\Restaurant;
use App\Entity\User;
use App\Service\Billing\StripeBilling;
use App\Service\RestaurantCreator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The reseller's panel, a /platform for its own restaurants: summary cards,
 * the list by paid_until with each one's status, "+ Nuevo restaurante",
 * "Registrar pago" / "Desactivar" (the restaurant's own paid_until, which
 * the reseller manages by hand — Restaurant::isExpired()), "Entrar" as its
 * owner (switch_user, see Security\SwitchUserVoter) and archive / restore
 * — never delete. While the reseller is expired the list still shows but
 * nothing can be changed. Every change in the count (archive / restore /
 * create — not a deactivation) updates the Stripe extra line (StripeBilling::syncResellerExtrasOrLog()).
 */
#[Route('/partners', name: 'partners_')]
#[IsGranted(User::ROLE_RESELLER)]
class PartnersController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly StripeBilling $billing,
        #[Autowire('%app.reseller_included_restaurants%')]
        private readonly int $includedRestaurants,
        #[Autowire('%app.connect_currency%')]
        private readonly string $connectCurrency,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderPanel();
    }

    #[Route('/restaurantes', name: 'restaurant_create', methods: ['POST'])]
    public function create(Request $request, RestaurantCreator $restaurantCreator): Response
    {
        $this->checkToken($request, 'partners-restaurant-create');
        $reseller = $this->reseller();
        if ($reseller->isExpired()) {
            return $this->redirectToRoute('partners_index');
        }

        $values = [
            'name'  => trim((string) $request->request->get('name')),
            'email' => trim((string) $request->request->get('email')),
        ];
        $password = (string) $request->request->get('password');

        $error = match (true) {
            mb_strlen($values['name']) < 2 || mb_strlen($values['name']) > 100 => 'new.error.name',
            !filter_var($values['email'], FILTER_VALIDATE_EMAIL)                => 'new.error.email_invalid',
            mb_strlen($password) < 8                                            => 'new.error.password_short',
            $restaurantCreator->isEmailTaken($values['email'])                  => 'new.error.email_taken',
            default                                                             => null,
        };
        if ($error !== null) {
            return $this->renderPanel(['error' => $this->translator->trans($error, [], 'partners'), 'values' => $values], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The owner's panel and menu start in the reseller's own language
        // (EUR, as the reseller pays in euros); the owner can change both.
        $language = $reseller->getAdminLocale();
        $restaurantCreator->create($values['name'], $values['email'], $password, 'EUR', $language, $language, $reseller);
        $this->billing->syncResellerExtrasOrLog($reseller);

        $this->addFlash('success', $this->translator->trans('flash.created', ['%name%' => $values['name']], 'partners'));

        return $this->redirectToRoute('partners_index');
    }

    #[Route('/restaurantes/{id}/archivar', name: 'restaurant_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id, Request $request): Response
    {
        $restaurant = $this->ownRestaurant($id);
        $this->checkToken($request, 'partners-restaurant-archive-' . $id);
        if (!$this->reseller()->isExpired()) {
            $restaurant->archive();
            $this->em->flush();
            $this->billing->syncResellerExtrasOrLog($this->reseller());
            $this->addFlash('success', $this->translator->trans('flash.archived', ['%name%' => $restaurant->getName()], 'partners'));
        }

        return $this->redirectToRoute('partners_index');
    }

    #[Route('/restaurantes/{id}/restaurar', name: 'restaurant_restore', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restore(int $id, Request $request): Response
    {
        $restaurant = $this->ownRestaurant($id);
        $this->checkToken($request, 'partners-restaurant-restore-' . $id);
        if (!$this->reseller()->isExpired()) {
            $restaurant->unarchive();
            $this->em->flush();
            $this->billing->syncResellerExtrasOrLog($this->reseller());
            $this->addFlash('success', $this->translator->trans('flash.restored', ['%name%' => $restaurant->getName()], 'partners'));
        }

        return $this->redirectToRoute('partners_index');
    }

    #[Route('/restaurantes/{id}/pago', name: 'restaurant_register_payment', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function registerPayment(int $id, Request $request): Response
    {
        $restaurant = $this->ownRestaurant($id);
        $this->checkToken($request, 'partners-restaurant-register-payment-' . $id);

        $plan = $request->request->get('plan');
        if (!isset(Restaurant::PLAN_MONTHS[$plan])) {
            throw new BadRequestHttpException('Unknown plan.');
        }

        if (!$this->reseller()->isExpired() && !$restaurant->isArchived()) {
            $restaurant->markPaid($plan);
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('flash.paid', [
                '%name%' => $restaurant->getName(),
                '%date%' => $restaurant->getPaidUntil()->format('d/m/Y'),
            ], 'partners'));
        }

        return $this->redirectToRoute('partners_index');
    }

    #[Route('/restaurantes/{id}/desactivar', name: 'restaurant_deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deactivate(int $id, Request $request): Response
    {
        $restaurant = $this->ownRestaurant($id);
        $this->checkToken($request, 'partners-restaurant-deactivate-' . $id);

        if (!$this->reseller()->isExpired() && !$restaurant->isArchived()) {
            $restaurant->deactivateNow();
            $this->em->flush();
            $this->addFlash('success', $this->translator->trans('flash.deactivated', ['%name%' => $restaurant->getName()], 'partners'));
        }

        return $this->redirectToRoute('partners_index');
    }

    /** @param array{error?: string, values?: array{name: string, email: string}} $dialog */
    private function renderPanel(array $dialog = [], int $status = Response::HTTP_OK): Response
    {
        $reseller    = $this->reseller();
        $restaurants = $this->em->getRepository(Restaurant::class)->findBy(['reseller' => $reseller], ['paidUntil' => 'ASC', 'name' => 'ASC']);

        // First owner per restaurant (lowest id with ROLE_OWNER), like /platform.
        $owners = [];
        if ($restaurants !== []) {
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurants], ['id' => 'ASC']) as $user) {
                $restaurantId = $user->getRestaurant()->getId();
                if (!isset($owners[$restaurantId]) && in_array(User::ROLE_OWNER, $user->getRoles(), true)) {
                    $owners[$restaurantId] = $user;
                }
            }
        }

        $active   = array_values(array_filter($restaurants, static fn (Restaurant $r) => !$r->isArchived()));
        $archived = array_values(array_filter($restaurants, static fn (Restaurant $r) => $r->isArchived()));

        // Summary cards, as /platform's: by each restaurant's own
        // paid_until (the reseller's own state has its own notice).
        // "Expiring soon" overlaps with active/trial on purpose.
        $summary = ['active' => 0, 'trial' => 0, 'expiringSoon' => 0, 'expired' => 0];
        foreach ($active as $restaurant) {
            if ($restaurant->isOwnPeriodExpired()) {
                $summary['expired']++;
                continue;
            }
            $summary[$restaurant->getPlan() === null ? 'trial' : 'active']++;
            if ($restaurant->getDaysLeft() <= 7) {
                $summary['expiringSoon']++;
            }
        }

        return $this->render('partners/index.html.twig', [
            'reseller' => $reseller,
            'active'   => $active,
            'summary'  => $summary,
            'archived' => $archived,
            'owners'   => $owners,
            'included' => $this->includedRestaurants,
            'extra'    => max(0, count($active) - $this->includedRestaurants),
            'dialog'   => $dialog,
            'currency' => strtolower($this->connectCurrency),
        ], new Response(status: $status));
    }

    /**
     * The app's change stands even if Stripe fails: logged, and the next
     * create / archive / restore sets the count again from scratch.
     */
    private function reseller(): Reseller
    {
        return $this->getUser()?->getReseller() ?? throw $this->createAccessDeniedException();
    }

    private function ownRestaurant(int $id): Restaurant
    {
        $restaurant = $this->em->find(Restaurant::class, $id);
        if ($restaurant === null || $restaurant->getReseller()?->getId() !== $this->reseller()->getId()) {
            throw $this->createNotFoundException();
        }

        return $restaurant;
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }
}
