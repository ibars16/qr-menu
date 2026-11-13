<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Changing a login email (User::$pendingEmail): the new address only takes
 * over once the link sent to it is clicked, so a typo can't lock anyone
 * out and nobody moves an account to their own inbox unnoticed (the old
 * address gets a notice when it happens).
 *
 * Same scheme as EmailConfirmation: a signed URL (7 days) with the user id
 * and a short hash of the pending address — asking for another address, or
 * cancelling, makes earlier links useless.
 */
final class EmailChange
{
    private const TTL = 'P7D';

    public const OK = 'ok';
    public const INVALID = 'invalid';
    /** Another account took that address while the link was waiting. */
    public const TAKEN = 'taken';

    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
        private readonly ResetPasswordRequestRepository $resetRequests,
        private readonly AccountMailer $mailer,
    ) {}

    /** Records $newEmail as pending and sends the link to it. */
    public function request(User $user, string $newEmail): void
    {
        $user->setPendingEmail($newEmail);
        $this->em->flush();
        $this->mailer->sendEmailChangeConfirmation($user, $this->url($user));
    }

    public function url(User $user): string
    {
        $url = $this->urls->generate('app_confirm_email_change', [
            'id' => $user->getId(),
            'check' => self::check((string) $user->getPendingEmail()),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateInterval(self::TTL));
    }

    /**
     * Applies the change the link stands for.
     *
     * @return array{0: string, 1: ?User} one of the constants, and the user when the link was theirs
     */
    public function confirm(Request $request, int $id, string $check): array
    {
        if (!$this->signer->checkRequest($request)) {
            return [self::INVALID, null];
        }
        $user = $this->em->find(User::class, $id);
        $pending = $user?->getPendingEmail();
        if ($user === null || $pending === null || !hash_equals(self::check($pending), $check)) {
            return [self::INVALID, null];
        }

        $owner = $this->em->getRepository(User::class)->findOneByEmail($pending);
        if ($owner !== null && $owner !== $user) {
            $user->setPendingEmail(null);
            $this->em->flush();

            return [self::TAKEN, $user];
        }

        $oldEmail = $user->getEmail();
        $user->setEmail($pending);
        $user->setPendingEmail(null);
        // Clicking proves this inbox is theirs: covers a sign-up's own confirmation too.
        if ($user->isEmailConfirmationPending()) {
            $user->setEmailConfirmationPending(false);
            $user->getRestaurant()?->bumpMenuContentVersion();
        }
        $this->resetRequests->removeRequests($user); // reset links went to the old inbox
        $this->em->flush();

        $this->mailer->sendEmailChangedNotice($user, $oldEmail);

        return [self::OK, $user];
    }

    private static function check(string $email): string
    {
        return substr(hash('sha256', 'change|' . mb_strtolower(trim($email))), 0, 16);
    }
}
