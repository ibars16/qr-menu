<?php

namespace App\Tests\Command;

use App\Command\CheckStripePricesCommand;
use App\Tests\Support\FakeStripeGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:stripe:check-prices against the fake Stripe (FakeStripeGateway): OK
 * when the 6 prices match what the app shows; a clear failure for a wrong
 * amount, currency, interval, an archived price, a missing variable or a
 * price Stripe doesn't have; tax_behavior other than "inclusive" fails in
 * prod and is only a warning elsewhere.
 */
final class CheckStripePricesCommandTest extends TestCase
{
    private const array IDS = ['monthly' => 'price_m', 'yearly' => 'price_y'];
    private const array RESELLER_IDS = ['monthly' => 'price_rm', 'yearly' => 'price_ry', 'extra_monthly' => 'price_rem', 'extra_yearly' => 'price_rey'];
    private const array BILLING = ['monthly' => 3999, 'yearly' => 39999];
    private const array RESELLER = ['monthly' => 40, 'yearly' => 400, 'extra_monthly' => 6, 'extra_yearly' => 60];

    protected function setUp(): void
    {
        FakeStripeGateway::reset();
        $this->price('price_m', 3999, 'month');
        $this->price('price_y', 39999, 'year');
        $this->price('price_rm', 4000, 'month');
        $this->price('price_ry', 40000, 'year');
        $this->price('price_rem', 600, 'month');
        $this->price('price_rey', 6000, 'year');
    }

    protected function tearDown(): void
    {
        FakeStripeGateway::reset();
    }

    /** @param array<string, mixed> $overrides */
    private function price(string $id, int $amount, string $interval, array $overrides = []): void
    {
        FakeStripeGateway::$objects[$id] = $overrides + [
            'id' => $id, 'object' => 'price', 'active' => true, 'livemode' => false, 'currency' => 'eur',
            'unit_amount' => $amount, 'recurring' => ['interval' => $interval], 'tax_behavior' => 'inclusive',
        ];
    }

    /** @param array<string, string> $ids */
    private function check(string $env = 'dev', array $ids = self::IDS): CommandTester
    {
        $tester = new CommandTester(new CheckStripePricesCommand(new FakeStripeGateway(), $ids, self::RESELLER_IDS, self::BILLING, self::RESELLER, $env));
        $tester->execute([]);

        return $tester;
    }

    private static function text(CommandTester $t): string
    {
        return preg_replace('/\s+/', ' ', $t->getDisplay());
    }

    public function testAllSixMatch(): void
    {
        $t = $this->check('prod');

        self::assertSame(Command::SUCCESS, $t->getStatusCode());
        self::assertStringContainsString('Los 6 precios de Stripe cuadran con los que muestra la app.', self::text($t));
        self::assertStringContainsString('Stripe en modo prueba', self::text($t));
        self::assertStringNotContainsString('WARNING', $t->getDisplay());
        self::assertSame(6, substr_count($t->getDisplay(), ' OK '));
    }

    public function testADifferentAmountFails(): void
    {
        $this->price('price_m', 3000, 'month');

        $t = $this->check();

        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('Restaurante mensual (STRIPE_PRICE_MONTHLY): Stripe cobra 30,00 € y la app muestra 39,99 €', self::text($t));
        self::assertStringContainsString('NO CUADRA', $t->getDisplay());
    }

    public function testCurrencyIntervalAndArchivedPriceFail(): void
    {
        $this->price('price_y', 39999, 'month');
        $this->price('price_rm', 4000, 'month', ['currency' => 'usd']);
        $this->price('price_rey', 6000, 'year', ['active' => false]);

        $t = self::text($this->check());

        self::assertStringContainsString('Restaurante anual (STRIPE_PRICE_YEARLY): en Stripe se cobra por month, debería ser año', $t);
        self::assertStringContainsString('Revendedor mensual (STRIPE_PRICE_RESELLER_MONTHLY): la moneda es USD, no EUR', $t);
        self::assertStringContainsString('Revendedor, restaurante extra anual (STRIPE_PRICE_RESELLER_EXTRA_YEARLY): el precio está archivado (no activo) en Stripe', $t);
    }

    public function testAMissingVariableOrUnknownPriceFails(): void
    {
        unset(FakeStripeGateway::$objects['price_y']);

        $t = $this->check('dev', ['monthly' => '', 'yearly' => 'price_y']);

        self::assertSame(Command::FAILURE, $t->getStatusCode());
        self::assertStringContainsString('Restaurante mensual (STRIPE_PRICE_MONTHLY): falta la variable STRIPE_PRICE_MONTHLY', self::text($t));
        self::assertStringContainsString('Restaurante anual (STRIPE_PRICE_YEARLY): STRIPE_PRICE_YEARLY no existe en Stripe o no se puede leer', self::text($t));
    }

    public function testTaxBehaviourOtherThanInclusiveFailsInProdOnly(): void
    {
        $this->price('price_rm', 4000, 'month', ['tax_behavior' => 'unspecified']);
        $message = 'Revendedor mensual (STRIPE_PRICE_RESELLER_MONTHLY): tax_behavior es "unspecified", debe ser "inclusive" (IVA incluido)';

        $dev = $this->check('dev');
        self::assertSame(Command::SUCCESS, $dev->getStatusCode());
        self::assertStringContainsString('Aviso (en producción sería un error): ' . $message, self::text($dev));

        $prod = $this->check('prod');
        self::assertSame(Command::FAILURE, $prod->getStatusCode());
        self::assertStringContainsString($message, self::text($prod));
        self::assertStringNotContainsString('Aviso (en producción', self::text($prod));
    }

    public function testItOnlyReads(): void
    {
        $this->check();

        self::assertSame(['retrievePrice'], array_values(array_unique(array_column(FakeStripeGateway::$accountLog, 0))));
        self::assertSame([null], array_values(array_unique(array_column(FakeStripeGateway::$accountLog, 1))), 'the platform account');
    }
}
