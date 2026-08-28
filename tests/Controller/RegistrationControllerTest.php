<?php

namespace App\Tests\Controller;

use App\Entity\Restaurant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The founding user of a brand-new restaurant must become its Owner — see the Owner/Staff split in UsersController and Version20260828064557's backfill for pre-existing accounts. */
final class RegistrationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?string $createdEmail = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $email = $this->createdEmail ?? null;
        if ($email) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user) {
                $restaurant = $user->getRestaurant();
                $this->em->remove($user);
                if ($restaurant) {
                    $this->em->remove($restaurant);
                }
                $this->em->flush();
            }
        }

        parent::tearDown();
    }

    public function testFoundingUserGetsOwnerRole(): void
    {
        $email = 'registration-test-' . uniqid() . '@example.test';
        $this->createdEmail = $email;

        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['registration_form[restaurantName]'] = 'Registration Test Restaurant';
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'EUR';
        $form['registration_form[language]'] = 'es';
        $form['registration_form[adminLocale]'] = 'es';

        $this->client->submit($form);

        self::assertResponseRedirects();

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, 'registration must have created the user');
        self::assertContains(User::ROLE_OWNER, $user->getRoles());
        self::assertInstanceOf(Restaurant::class, $user->getRestaurant());
    }
}
