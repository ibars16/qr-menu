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
        // The page follows the browser's language (no header = the first admin locale).
        $this->client = static::createClient(server: ['HTTP_ACCEPT_LANGUAGE' => 'es']);
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

        $form = $crawler->filter('form:not(.lang-form)')->form();
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
        // Ours keep the free trial (a reseller's restaurant has none).
        self::assertSame((new \DateTimeImmutable('today +30 days'))->format('Y-m-d'), $user->getRestaurant()->getPaidUntil()->format('Y-m-d'));
    }

    /**
     * The menu's main language offers every public-menu language
     * (config/languages.php), shown like Configuración's (flag + name),
     * the form's own language preselected (Spanish here).
     */
    public function testTheMenuLanguageOffersEveryPublicMenuLanguage(): void
    {
        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $languages = require static::getContainer()->getParameter('kernel.project_dir') . '/config/languages.php';
        $options = $crawler->filter('select[name="registration_form[language]"] option');
        self::assertSame(array_keys($languages), $options->each(static fn ($o) => $o->attr('value')));
        self::assertCount(20, $options);
        self::assertSame(
            array_values(array_map(static fn (array $l) => $l['flag'] . ' ' . $l['name'], $languages)),
            $options->each(static fn ($o) => trim($o->text())),
        );
        self::assertSame('es', $crawler->filter('select[name="registration_form[language]"] option[selected]')->attr('value'));
    }

    private function preselectedMenuLanguage(): ?string
    {
        $selected = $this->client->getCrawler()->filter('select[name="registration_form[language]"] option[selected]');

        return $selected->count() > 0 ? $selected->attr('value') : null;
    }

    /** A German browser: the form is in German, and so is the menu language preselected. */
    public function testTheMenuLanguagePreselectedIsTheFormsLanguage(): void
    {
        $this->client->request('GET', '/register', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        self::assertResponseIsSuccessful();
        self::assertSame('de', $this->client->getRequest()->getLocale());
        self::assertSame('de', $this->preselectedMenuLanguage());
    }

    /** Picked on the login's language selector (Italian, on a Spanish browser): that one. */
    public function testTheMenuLanguageFollowsTheLanguagePickedOnTheSelector(): void
    {
        $this->client->request('GET', '/register');
        $this->client->request('POST', '/admin/login/idioma', ['locale' => 'it', 'back' => '/register']);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame('it', $this->preselectedMenuLanguage());
    }

    /** A form language that isn't a public-menu one (none of the 6 today): Spanish. */
    public function testAFormLanguageThatIsNoMenuLanguageFallsBackToSpanish(): void
    {
        $form = static::getContainer()->get('form.factory')->create(\App\Form\RegistrationFormType::class, options: ['display_locale' => 'xx']);
        self::assertSame('es', $form->get('language')->getData());

        $form = static::getContainer()->get('form.factory')->create(\App\Form\RegistrationFormType::class, options: ['display_locale' => 'fr']);
        self::assertSame('fr', $form->get('language')->getData());
    }

    /** A language outside the old fixed six (Italian) is stored as the menu's main language. */
    public function testTheChosenMenuLanguageIsStored(): void
    {
        $email = 'registration-test-' . uniqid() . '@example.test';
        $this->createdEmail = $email;

        $form = $this->client->request('GET', '/register')->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Registration Test Trattoria';
        $form['registration_form[email]'] = $email;
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'EUR';
        $form['registration_form[language]'] = 'it';
        $form['registration_form[adminLocale]'] = 'es';
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertSame('it', $user->getRestaurant()->getDefaultLanguage());
        self::assertSame('maison', $user->getRestaurant()->getTheme(), 'new restaurants start on the light theme');
    }

    public function testAnUnknownMenuLanguageIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $token = $crawler->filter('input[name="registration_form[_token]"]')->attr('value');
        $email = 'registration-test-' . uniqid() . '@example.test';
        $this->client->request('POST', '/register', ['registration_form' => [
            'restaurantName' => 'Registration Test Unknown', 'email' => $email,
            'password' => ['first' => 'a-strong-password-1', 'second' => 'a-strong-password-1'],
            'currency' => 'EUR', 'language' => 'xx', 'adminLocale' => 'es', '_token' => $token,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => $email]));
    }

    public function testTakenEmailShowsErrorAndKeepsWhatWasTyped(): void
    {
        $existing = new User();
        $existing->setEmail('registration-taken-' . uniqid() . '@example.test');
        $existing->setPassword('unused-in-tests');
        $this->em->persist($existing);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Registration Taken Restaurant';
        $form['registration_form[email]'] = $existing->getEmail();
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $form['registration_form[currency]'] = 'GBP';
        $form['registration_form[language]'] = 'fr';
        $form['registration_form[adminLocale]'] = 'es';
        $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        $page = $this->client->getCrawler();
        self::assertContains('Ya hay una cuenta con ese email.', $page->filter('.errors li')->each(fn ($li) => $li->text()));
        self::assertSame('Registration Taken Restaurant', $page->filter('input[name="registration_form[restaurantName]"]')->attr('value'));
        self::assertSame($existing->getEmail(), $page->filter('input[name="registration_form[email]"]')->attr('value'));
        self::assertSame('GBP', $page->filter('select[name="registration_form[currency]"] option[selected]')->attr('value'));
        self::assertSame('fr', $page->filter('select[name="registration_form[language]"] option[selected]')->attr('value'));

        $this->em->clear();
        self::assertNull($this->em->getRepository(Restaurant::class)->findOneBy(['name' => 'Registration Taken Restaurant']));
        $this->em->remove($this->em->find(User::class, $existing->getId()));
        $this->em->flush();
    }

    /**
     * One submit tells everything: the taken email comes along with the
     * other errors, the wrong fields are marked, and the taken email offers
     * signing in / "forgot password" with the email filled in.
     */
    public function testTakenEmailIsReportedAlongWithOtherErrorsAndLinksToSignIn(): void
    {
        $existing = new User();
        $existing->setEmail('registration-taken2-' . uniqid() . '@example.test');
        $existing->setPassword('unused-in-tests');
        $this->em->persist($existing);
        $this->em->flush();

        $form = $this->client->request('GET', '/register')->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Registration Taken2 Restaurant';
        $form['registration_form[email]'] = $existing->getEmail();
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'another-password-2';
        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame(['Ya hay una cuenta con ese email.', 'Las contraseñas no coinciden.'], $page->filter('.errors li')->each(fn ($li) => $li->text()));
        self::assertSame(
            ['registration_form[email]', 'registration_form[password][first]', 'registration_form[password][second]'],
            $page->filter('.is-invalid[aria-invalid="true"]')->each(fn ($el) => $el->attr('name')),
        );
        $email = $existing->getEmail();
        self::assertSame(
            ['/admin/login?email=' . $email, '/admin/login/recuperar?email=' . $email],
            $page->filter('.error-actions a')->each(fn ($a) => $a->attr('href')),
        );

        $this->em->remove($this->em->find(User::class, $existing->getId()));
        $this->em->flush();
    }

    public function testTheEmailComesFilledInFromTheLogin(): void
    {
        $page = $this->client->request('GET', '/register?email=someone%40example.test');

        self::assertSame('someone@example.test', $page->filter('input[name="registration_form[email]"]')->attr('value'));
        self::assertCount(0, $page->filter('.errors li'));
    }

    public function testShortPasswordShowsNextToPasswordAndKeepsWhatWasTyped(): void
    {
        $crawler = $this->client->request('GET', '/register');
        // Turbo would reload this page (turbo-visit-control) and drop the errors.
        self::assertSame('false', $crawler->filter('form:not(.lang-form)')->attr('data-turbo'));

        $form = $crawler->filter('form:not(.lang-form)')->form();
        $form['registration_form[restaurantName]'] = 'Registration Short Restaurant';
        $form['registration_form[email]'] = 'registration-short@example.test';
        $form['registration_form[password][first]'] = 'short';
        $form['registration_form[password][second]'] = 'short';
        $form['registration_form[currency]'] = 'GBP';
        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        $field = $page->filter('input[name="registration_form[password][first]"]')->closest('.field');
        self::assertSame(['Mínimo 8 caracteres.'], $field->filter('.errors li')->each(fn ($li) => $li->text()));
        self::assertSame('Registration Short Restaurant', $page->filter('input[name="registration_form[restaurantName]"]')->attr('value'));
        self::assertSame('registration-short@example.test', $page->filter('input[name="registration_form[email]"]')->attr('value'));
        self::assertSame('GBP', $page->filter('select[name="registration_form[currency]"] option[selected]')->attr('value'));
        self::assertNull($page->filter('input[name="registration_form[password][first]"]')->attr('value'));
    }

    public function testExpiredPageShowsGeneralError(): void
    {
        $form = $this->client->request('GET', '/register')->filter('form:not(.lang-form)')->form();
        $form['registration_form[_token]'] = 'expired';
        $form['registration_form[restaurantName]'] = 'Registration Expired Restaurant';
        $form['registration_form[email]'] = 'registration-expired@example.test';
        $form['registration_form[password][first]'] = 'a-strong-password-1';
        $form['registration_form[password][second]'] = 'a-strong-password-1';
        $page = $this->client->submit($form);

        self::assertResponseIsUnprocessable();
        self::assertSame('La página ha caducado. Vuelve a enviar el formulario.', $page->filter('.form-errors[role="alert"] li')->text());
        self::assertSame('Registration Expired Restaurant', $page->filter('input[name="registration_form[restaurantName]"]')->attr('value'));
    }
}
