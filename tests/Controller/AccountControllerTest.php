<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Mi cuenta" (AccountController): a panel user changes their own email or
 * password, always behind the current password, and stays logged in after.
 * A new email only takes over once the link sent to it is clicked
 * (EmailChange) — from Mi cuenta and from the team screen alike.
 */
final class AccountControllerTest extends WebTestCase
{
    private const PASSWORD = 'current-password-1';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    /** @var int[] */
    private array $restaurantIdsToRemove = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->restaurantIdsToRemove as $restaurantId) {
            $restaurant = $this->em->getRepository(Restaurant::class)->find($restaurantId);
            if (!$restaurant) {
                continue;
            }
            foreach ($this->em->getRepository(User::class)->findBy(['restaurant' => $restaurant]) as $user) {
                $this->em->remove($user);
            }
            $this->em->remove($restaurant);
        }
        $this->em->flush();

        parent::tearDown();
    }

    private function makeRestaurant(): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->setName('Account Test');
        $restaurant->setSlug('account-test-' . uniqid());
        $restaurant->setPrimaryColor('#000000');
        $restaurant->setCurrency('EUR');
        $restaurant->setDefaultLanguage('es');
        $this->em->persist($restaurant);
        $this->em->flush();
        $this->restaurantIdsToRemove[] = $restaurant->getId();

        return $restaurant;
    }

    private function makeUser(Restaurant $restaurant, string $role): User
    {
        $user = new User();
        $user->setEmail('account-test-' . uniqid() . '@example.test');
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $user->setRoles([$role]);
        $user->setRestaurant($restaurant);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->find($user->getId());
    }

    /** @param array<string, string> $fields */
    private function submit(string $button, array $fields): void
    {
        $crawler = $this->client->request('GET', '/admin/cuenta');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton($button)->form(), $fields);
    }

    public function testStaffCanChangeOwnPasswordAndStaysLoggedIn(): void
    {
        $staff = $this->makeUser($this->makeRestaurant(), User::ROLE_STAFF);
        $this->client->loginUser($staff);

        $this->submit('Cambiar contraseña', [
            'current_password' => self::PASSWORD,
            'new_password' => 'brand-new-password',
            'new_password_confirm' => 'brand-new-password',
        ]);
        self::assertResponseRedirects('/admin/cuenta');

        $staff = $this->reload($staff);
        self::assertTrue($this->hasher->isPasswordValid($staff, 'brand-new-password'));

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-success', 'Contraseña cambiada');
    }

    public function testWrongCurrentPasswordChangesNothing(): void
    {
        $owner = $this->makeUser($this->makeRestaurant(), User::ROLE_OWNER);
        $oldEmail = $owner->getEmail();
        $this->client->loginUser($owner);

        $this->submit('Cambiar email', ['email' => 'other-' . uniqid() . '@example.test', 'current_password' => 'wrong']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#err-current_password', 'no es correcta');

        $this->submit('Cambiar contraseña', [
            'current_password' => 'wrong',
            'new_password' => 'brand-new-password',
            'new_password_confirm' => 'brand-new-password',
        ]);
        self::assertResponseStatusCodeSame(422);

        $owner = $this->reload($owner);
        self::assertSame($oldEmail, $owner->getEmail());
        self::assertTrue($this->hasher->isPasswordValid($owner, self::PASSWORD));
    }

    public function testEmailAlreadyTakenIsRejected(): void
    {
        $restaurant = $this->makeRestaurant();
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $other = $this->makeUser($restaurant, User::ROLE_STAFF);
        $this->client->loginUser($owner);

        $this->submit('Cambiar email', ['email' => $other->getEmail(), 'current_password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#err-email', 'ya está en uso');
    }

    public function testPasswordConfirmationMustMatchAndBeLongEnough(): void
    {
        $owner = $this->makeUser($this->makeRestaurant(), User::ROLE_OWNER);
        $this->client->loginUser($owner);

        $this->submit('Cambiar contraseña', [
            'current_password' => self::PASSWORD,
            'new_password' => 'brand-new-password',
            'new_password_confirm' => 'something-else',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#err-new_password_confirm');

        $this->submit('Cambiar contraseña', [
            'current_password' => self::PASSWORD,
            'new_password' => 'short',
            'new_password_confirm' => 'short',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('#err-new_password');

        self::assertTrue($this->hasher->isPasswordValid($this->reload($owner), self::PASSWORD));
    }

    public function testOwnerFindsMyAccountInsideSettingsNotInTheSidebar(): void
    {
        $owner = $this->makeUser($this->makeRestaurant(), User::ROLE_OWNER);
        $this->client->loginUser($owner);

        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.settings-wrap a.settings-account-link[href="/admin/cuenta"]');
        self::assertSelectorNotExists('.sidebar a[href="/admin/cuenta"]');

        // On the account screen, Configuración stays highlighted.
        $this->client->request('GET', '/admin/cuenta');
        self::assertSelectorExists('.sidebar a.nav-item.active[href="/admin/settings"]');
    }

    public function testStaffWithoutSettingsKeepsADirectLink(): void
    {
        $staff = $this->makeUser($this->makeRestaurant(), User::ROLE_STAFF);
        $this->client->loginUser($staff);

        $this->client->request('GET', '/admin/cuenta');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar a[href="/admin/cuenta"]');
        self::assertSelectorNotExists('a.account-back');
    }

    /** Emails actually sent by the last request. @return Email[] */
    private static function sent(): array
    {
        $events = array_filter(self::getMailerEvents(), static fn ($e) => !$e->isQueued());

        return array_values(array_map(static fn ($e) => $e->getMessage(), $events));
    }

    private static function changeLinkIn(Email $email): string
    {
        self::assertSame(1, preg_match('#https?://[^"]+(/confirmar-cambio-email/\d+/[0-9a-f]{16}\?[^"]+)#', (string) $email->getHtmlBody(), $m));

        return html_entity_decode($m[1]);
    }

    private function requestOwnChange(User $user, string $newEmail): string
    {
        $this->submit('Cambiar email', ['email' => '  ' . strtoupper($newEmail) . ' ', 'current_password' => self::PASSWORD]);
        self::assertResponseRedirects('/admin/cuenta');
        $sent = self::sent();
        self::assertCount(1, $sent);
        self::assertSame($newEmail, $sent[0]->getTo()[0]->getAddress());

        return self::changeLinkIn($sent[0]);
    }

    public function testNewEmailOnlyTakesOverOnceConfirmedAndOldAddressIsNotified(): void
    {
        $owner = $this->makeUser($this->makeRestaurant(), User::ROLE_OWNER);
        $oldEmail = $owner->getEmail();
        $this->client->loginUser($owner);

        $newEmail = 'account-new-' . uniqid() . '@example.test';
        $link = $this->requestOwnChange($owner, $newEmail);

        $owner = $this->reload($owner);
        self::assertSame($oldEmail, $owner->getEmail(), 'nothing changes until the link is clicked');
        self::assertSame($newEmail, $owner->getPendingEmail());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.account-pending', $newEmail);

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/admin/cuenta');
        $sent = self::sent();
        self::assertCount(1, $sent);
        self::assertSame($oldEmail, $sent[0]->getTo()[0]->getAddress());
        self::assertStringContainsString($newEmail, (string) $sent[0]->getHtmlBody());

        $owner = $this->reload($owner);
        self::assertSame($newEmail, $owner->getEmail());
        self::assertNull($owner->getPendingEmail());

        // Still logged in, now under the new address.
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.account-current strong', $newEmail);
        self::assertSelectorNotExists('.account-pending');

        // The link only works once.
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(400);
    }

    public function testLinkOpenedLoggedOutShowsAConfirmationPage(): void
    {
        $owner = $this->makeUser($this->makeRestaurant(), User::ROLE_OWNER);
        $this->client->loginUser($owner);
        $newEmail = 'account-new-' . uniqid() . '@example.test';
        $link = $this->requestOwnChange($owner, $newEmail);

        $this->client->request('GET', '/admin/logout');
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', $newEmail);
        self::assertSame($newEmail, $this->reload($owner)->getEmail());
    }

    public function testCancellingKillsTheLink(): void
    {
        $owner = $this->makeUser($this->makeRestaurant(), User::ROLE_OWNER);
        $oldEmail = $owner->getEmail();
        $this->client->loginUser($owner);
        $link = $this->requestOwnChange($owner, 'account-new-' . uniqid() . '@example.test');

        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->filter('#email-change-cancel')->form());
        self::assertResponseRedirects('/admin/cuenta');
        self::assertNull($this->reload($owner)->getPendingEmail());

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($oldEmail, $this->reload($owner)->getEmail());
    }

    public function testAddressTakenMeanwhileIsNotApplied(): void
    {
        $restaurant = $this->makeRestaurant();
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $oldEmail = $owner->getEmail();
        $this->client->loginUser($owner);
        $newEmail = 'account-new-' . uniqid() . '@example.test';
        $link = $this->requestOwnChange($owner, $newEmail);

        $this->em->clear();
        $other = $this->makeUser($this->em->find(Restaurant::class, $restaurant->getId()), User::ROLE_STAFF);
        $other->setEmail($newEmail);
        $this->em->flush();

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/admin/cuenta');
        $owner = $this->reload($owner);
        self::assertSame($oldEmail, $owner->getEmail());
        self::assertNull($owner->getPendingEmail());
    }

    public function testConfirmingTheNewAddressAlsoConfirmsAnUnconfirmedSignUp(): void
    {
        $owner = $this->makeUser($this->makeRestaurant(), User::ROLE_OWNER);
        $owner->setEmailConfirmationPending(true);
        $this->em->flush();
        $this->client->loginUser($owner);

        $link = $this->requestOwnChange($owner, 'account-new-' . uniqid() . '@example.test');
        self::assertTrue($this->reload($owner)->isEmailConfirmationPending(), 'asking proves nothing yet');

        $this->client->request('GET', $link);
        self::assertFalse($this->reload($owner)->isEmailConfirmationPending());
    }

    public function testOwnerChangingATeamMembersEmailAlsoWaitsForConfirmation(): void
    {
        $restaurant = $this->makeRestaurant();
        $owner = $this->makeUser($restaurant, User::ROLE_OWNER);
        $staff = $this->makeUser($restaurant, User::ROLE_STAFF);
        $taken = $this->makeUser($restaurant, User::ROLE_STAFF);
        $staffEmail = $staff->getEmail();
        $this->client->loginUser($owner);

        $this->client->request(
            'POST', "/admin/usuarios/{$staff->getId()}/editar", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $taken->getEmail(), 'role' => 'staff'])
        );
        self::assertResponseStatusCodeSame(400);

        $newEmail = 'team-new-' . uniqid() . '@example.test';
        $this->client->request(
            'POST', "/admin/usuarios/{$staff->getId()}/editar", [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $newEmail, 'role' => 'staff'])
        );
        self::assertResponseIsSuccessful();
        self::assertSame($newEmail, json_decode($this->client->getResponse()->getContent(), true)['emailChangeSent']);
        $sent = self::sent();
        self::assertCount(1, $sent);
        self::assertSame($newEmail, $sent[0]->getTo()[0]->getAddress());

        $staff = $this->reload($staff);
        self::assertSame($staffEmail, $staff->getEmail());
        self::assertSame($newEmail, $staff->getPendingEmail());

        $this->client->request('GET', '/admin/usuarios');
        self::assertSelectorTextContains('.user-pending', $newEmail);

        // Staff (logged out here: the link was opened from their inbox) confirms.
        $this->client->request('GET', '/admin/logout');
        $this->client->request('GET', self::changeLinkIn($sent[0]));
        self::assertResponseIsSuccessful();
        self::assertSame($newEmail, $this->reload($staff)->getEmail());
    }
}
