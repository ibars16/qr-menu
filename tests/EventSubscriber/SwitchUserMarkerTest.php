<?php

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\SwitchUserMarker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;

/** ROLE_PREVIOUS_ADMIN goes on the switched token only, and only for a super-admin switching. */
final class SwitchUserMarkerTest extends TestCase
{
    private static function user(string $email, string $role): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('unused');
        $user->setRoles([$role]);

        return $user;
    }

    private static function switched(string $impersonatorRole): SwitchUserEvent
    {
        $impersonator = self::user('impersonator@example.test', $impersonatorRole);
        $owner = self::user('owner@example.test', User::ROLE_OWNER);
        $original = new UsernamePasswordToken($impersonator, 'main', $impersonator->getRoles());
        $token = new SwitchUserToken($owner, 'main', $owner->getRoles(), $original, '/admin/billing/pay/1');

        return new SwitchUserEvent(new Request(), $owner, $token);
    }

    public function testSuperAdminSwitchGetsMarkerOnTokenOnly(): void
    {
        $event = self::switched(User::ROLE_SUPER_ADMIN);
        (new SwitchUserMarker())->onSwitchUser($event);

        $token = $event->getToken();
        self::assertInstanceOf(SwitchUserToken::class, $token);
        self::assertEqualsCanonicalizing([User::ROLE_OWNER, 'ROLE_USER', 'ROLE_PREVIOUS_ADMIN'], $token->getRoleNames());
        self::assertSame('/admin/billing/pay/1', $token->getOriginatedFromUri());
        self::assertSame('impersonator@example.test', $token->getOriginalToken()->getUserIdentifier());
        self::assertNotContains('ROLE_PREVIOUS_ADMIN', $token->getUser()->getRoles());
    }

    public function testOtherSwitcherGetsNoMarker(): void
    {
        $event = self::switched(User::ROLE_OWNER);
        $before = $event->getToken();
        (new SwitchUserMarker())->onSwitchUser($event);

        self::assertSame($before, $event->getToken());
    }

    public function testExitEventIsLeftAlone(): void
    {
        $super = self::user('super@example.test', User::ROLE_SUPER_ADMIN);
        $original = new UsernamePasswordToken($super, 'main', $super->getRoles());
        $event = new SwitchUserEvent(new Request(), $super, $original);
        (new SwitchUserMarker())->onSwitchUser($event);

        self::assertSame($original, $event->getToken());
    }
}
