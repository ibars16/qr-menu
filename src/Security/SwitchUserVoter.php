<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may switch_user (security.yaml's switch_user.role) into whom:
 * the super-admin into anyone; a reseller that is up to date only into the
 * owner of one of its own restaurants. Everyone else, never.
 *
 * @extends Voter<string, User>
 */
final class SwitchUserVoter extends Voter
{
    public const ATTRIBUTE = 'CAN_SWITCH_USER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array(User::ROLE_SUPER_ADMIN, $user->getRoles(), true)) {
            return true;
        }

        $reseller = $user->getReseller();
        if (!in_array(User::ROLE_RESELLER, $user->getRoles(), true) || $reseller === null || $reseller->isExpired()) {
            return false;
        }

        return in_array(User::ROLE_OWNER, $subject->getRoles(), true)
            && $subject->getRestaurant()?->getReseller()?->getId() === $reseller->getId();
    }
}
