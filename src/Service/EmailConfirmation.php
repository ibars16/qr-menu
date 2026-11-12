<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Confirma tu email" for self sign-ups (User::$emailConfirmationPending).
 *
 * The link is a signed URL (UriSigner, 7 days) carrying the user id and a
 * short hash of the email it was sent to: changing the account's email
 * makes old links useless. Nothing is stored — a new link (resend) doesn't
 * invalidate the earlier ones, and confirming twice is harmless.
 */
final class EmailConfirmation
{
    private const TTL = 'P7D';

    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $em,
    ) {}

    public function url(User $user): string
    {
        $url = $this->urls->generate('app_confirm_email', [
            'id' => $user->getId(),
            'check' => self::check($user->getEmail()),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->signer->sign($url, new \DateInterval(self::TTL));
    }

    /**
     * The user the request's link confirms (now confirmed and flushed), or
     * null when the link is tampered with, expired, or for another email.
     */
    public function confirm(Request $request, int $id, string $check): ?User
    {
        if (!$this->signer->checkRequest($request)) {
            return null;
        }
        $user = $this->em->find(User::class, $id);
        if ($user === null || !hash_equals(self::check($user->getEmail()), $check)) {
            return null;
        }
        if ($user->isEmailConfirmationPending()) {
            $user->setEmailConfirmationPending(false);
            $user->getRestaurant()?->bumpMenuContentVersion();
            $this->em->flush();
        }

        return $user;
    }

    private static function check(string $email): string
    {
        return substr(hash('sha256', mb_strtolower(trim($email))), 0, 16);
    }
}
