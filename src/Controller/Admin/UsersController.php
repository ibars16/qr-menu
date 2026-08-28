<?php

namespace App\Controller\Admin;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Team management — Owner-only (see security.yaml's role_hierarchy and the
 * ROLE_STAFF/ROLE_OWNER split across Admin\*Controller). Every mutating
 * action re-checks ownership the same way TagsController does, plus two
 * safety checks that don't exist anywhere else in this codebase: a
 * restaurant can never be left with zero Owners, and nobody can delete
 * their own account mid-session.
 */
#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_OWNER')]
class UsersController extends AbstractController
{
    private function restaurant(): Restaurant
    {
        $r = $this->getUser()->getRestaurant();
        if (!$r) {
            throw $this->createAccessDeniedException();
        }
        return $r;
    }

    /** True once removing/demoting $user would leave its restaurant with zero ROLE_OWNER users. */
    private function isLastOwner(User $user): bool
    {
        if (!in_array(User::ROLE_OWNER, $user->getRoles(), true)) {
            return false;
        }

        foreach ($user->getRestaurant()->getUsers() as $other) {
            if ($other !== $user && in_array(User::ROLE_OWNER, $other->getRoles(), true)) {
                return false;
            }
        }

        return true;
    }

    private function roleFromInput(?string $role): ?string
    {
        return match ($role) {
            'owner' => User::ROLE_OWNER,
            'staff' => User::ROLE_STAFF,
            default => null,
        };
    }

    #[Route('/usuarios', name: 'users')]
    public function index(): Response
    {
        $restaurant = $this->restaurant();
        $users      = $restaurant->getUsers()->toArray();
        usort($users, fn (User $a, User $b) => strcasecmp($a->getEmail(), $b->getEmail()));

        return $this->render('admin/users.html.twig', [
            'restaurant'    => $restaurant,
            'users'         => $users,
            'currentUserId' => $this->getUser()->getId(),
        ]);
    }

    #[Route('/usuarios/crear', name: 'user_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        TranslatorInterface $translator,
    ): JsonResponse {
        $restaurant = $this->restaurant();
        $data       = json_decode($request->getContent(), true);

        $email = trim($data['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => $translator->trans('error.email_invalid', domain: 'admin_users')], 400);
        }
        if ($em->getRepository(User::class)->findOneBy(['email' => $email])) {
            return $this->json(['error' => $translator->trans('error.email_taken', domain: 'admin_users')], 400);
        }

        $password = (string) ($data['password'] ?? '');
        if (mb_strlen($password) < 8) {
            return $this->json(['error' => $translator->trans('error.password_too_short', domain: 'admin_users')], 400);
        }

        $role = $this->roleFromInput($data['role'] ?? null);
        if (!$role) {
            return $this->json(['error' => $translator->trans('error.invalid_role', domain: 'admin_users')], 400);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName(trim($data['firstName'] ?? '') ?: null);
        $user->setLastName(trim($data['lastName'] ?? '') ?: null);
        $user->setRoles([$role]);
        $user->setPassword($hasher->hashPassword($user, $password));
        $user->setRestaurant($restaurant);

        $em->persist($user);
        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/usuarios/{id}/editar', name: 'user_update', methods: ['POST'])]
    public function update(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        TranslatorInterface $translator,
    ): JsonResponse {
        if ($user->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data = json_decode($request->getContent(), true);

        $role = $this->roleFromInput($data['role'] ?? null);
        if (!$role) {
            return $this->json(['error' => $translator->trans('error.invalid_role', domain: 'admin_users')], 400);
        }

        if ($role !== User::ROLE_OWNER && $this->isLastOwner($user)) {
            return $this->json(['error' => $translator->trans('error.cannot_demote_last_owner', domain: 'admin_users')], 400);
        }

        $password = (string) ($data['password'] ?? '');
        if ($password !== '' && mb_strlen($password) < 8) {
            return $this->json(['error' => $translator->trans('error.password_too_short', domain: 'admin_users')], 400);
        }

        $user->setFirstName(trim($data['firstName'] ?? '') ?: null);
        $user->setLastName(trim($data['lastName'] ?? '') ?: null);
        $user->setRoles([$role]);
        if ($password !== '') {
            $user->setPassword($hasher->hashPassword($user, $password));
        }

        $em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/usuarios/{id}/eliminar', name: 'user_delete', methods: ['POST'])]
    public function delete(User $user, EntityManagerInterface $em, TranslatorInterface $translator): JsonResponse
    {
        if ($user->getRestaurant() !== $this->restaurant()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        if ($user === $this->getUser()) {
            return $this->json(['error' => $translator->trans('error.cannot_delete_self', domain: 'admin_users')], 400);
        }

        if ($this->isLastOwner($user)) {
            return $this->json(['error' => $translator->trans('error.cannot_delete_last_owner', domain: 'admin_users')], 400);
        }

        $em->remove($user);
        $em->flush();

        return $this->json(['ok' => true]);
    }
}
