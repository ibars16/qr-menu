<?php

namespace App\Command;

use App\Service\Billing\StripeGatewayInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-only: the 6 Stripe Prices the app charges (STRIPE_PRICE_*) against
 * the prices it SHOWS (app.billing_prices in cents, app.reseller_prices in
 * euros — config/services.yaml). Fails (exit 1) when one is missing, not
 * found, inactive, not EUR, not the right interval or not the same amount,
 * so a deploy that runs it stops instead of charging something else than
 * the page says. tax_behavior must be "inclusive" (the terms say VAT
 * included): a failure in prod, only a warning elsewhere.
 *
 * Run it on every deploy: php bin/console app:stripe:check-prices --env=prod
 */
#[AsCommand(
    name: 'app:stripe:check-prices',
    description: 'Comprueba que los precios de Stripe coinciden con los que muestra la app (solo lectura).'
)]
class CheckStripePricesCommand extends Command
{
    /**
     * @param array<string, string> $priceIds         monthly, yearly
     * @param array<string, string> $resellerPriceIds monthly, yearly, extra_monthly, extra_yearly
     * @param array<string, int>    $billingPrices    cents
     * @param array<string, int>    $resellerPrices   euros
     */
    public function __construct(
        private readonly StripeGatewayInterface $stripe,
        #[Autowire(['monthly' => '%env(STRIPE_PRICE_MONTHLY)%', 'yearly' => '%env(STRIPE_PRICE_YEARLY)%'])]
        private readonly array $priceIds,
        #[Autowire([
            'monthly' => '%env(STRIPE_PRICE_RESELLER_MONTHLY)%',
            'yearly' => '%env(STRIPE_PRICE_RESELLER_YEARLY)%',
            'extra_monthly' => '%env(STRIPE_PRICE_RESELLER_EXTRA_MONTHLY)%',
            'extra_yearly' => '%env(STRIPE_PRICE_RESELLER_EXTRA_YEARLY)%',
        ])]
        private readonly array $resellerPriceIds,
        #[Autowire('%app.billing_prices%')] private readonly array $billingPrices,
        #[Autowire('%app.reseller_prices%')] private readonly array $resellerPrices,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strictTax = $this->environment === 'prod';

        $checks = [];
        foreach (['monthly', 'yearly'] as $plan) {
            $checks[] = ['Restaurante ' . self::planName($plan), 'STRIPE_PRICE_' . strtoupper($plan), $this->priceIds[$plan] ?? '', (int) $this->billingPrices[$plan], $plan];
        }
        foreach (['monthly', 'yearly', 'extra_monthly', 'extra_yearly'] as $plan) {
            $interval = str_ends_with($plan, 'yearly') ? 'yearly' : 'monthly';
            $label = (str_starts_with($plan, 'extra_') ? 'Revendedor, restaurante extra ' : 'Revendedor ') . self::planName($interval);
            $checks[] = [$label, 'STRIPE_PRICE_RESELLER_' . strtoupper($plan), $this->resellerPriceIds[$plan] ?? '', (int) $this->resellerPrices[$plan] * 100, $interval];
        }

        $errors = [];
        $warnings = [];
        $rows = [];
        $modes = [];
        foreach ($checks as [$label, $var, $id, $expected, $interval]) {
            $problems = [];
            $shown = '—';
            if (trim($id) === '') {
                $problems[] = "falta la variable $var";
            } else {
                try {
                    $price = $this->stripe->retrievePrice($id);
                } catch (\Throwable $e) {
                    $price = null;
                    $problems[] = "$var no existe en Stripe o no se puede leer (" . (new \ReflectionClass($e))->getShortName() . ')';
                }
                if ($price !== null) {
                    $modes[] = !empty($price['livemode']) ? 'real' : 'prueba';
                    $amount = $price['unit_amount'] ?? null;
                    $currency = strtolower((string) ($price['currency'] ?? ''));
                    $stripeInterval = $price['recurring']['interval'] ?? null;
                    $shown = ($amount === null ? '?' : self::euros((int) $amount)) . ' ' . strtoupper($currency) . ' / ' . ($stripeInterval === 'year' ? 'año' : ($stripeInterval === 'month' ? 'mes' : '?'));

                    if (empty($price['active'])) {
                        $problems[] = 'el precio está archivado (no activo) en Stripe';
                    }
                    if ($currency !== 'eur') {
                        $problems[] = 'la moneda es ' . strtoupper($currency ?: '?') . ', no EUR';
                    }
                    if ($stripeInterval !== ($interval === 'yearly' ? 'year' : 'month')) {
                        $problems[] = 'en Stripe se cobra por ' . ($stripeInterval ?? 'pago único') . ', debería ser ' . ($interval === 'yearly' ? 'año' : 'mes');
                    }
                    if ($amount !== $expected) {
                        $problems[] = 'Stripe cobra ' . ($amount === null ? '?' : self::euros((int) $amount) . ' €') . ' y la app muestra ' . self::euros($expected) . ' €';
                    }
                    if (($price['tax_behavior'] ?? null) !== 'inclusive') {
                        $tax = 'tax_behavior es "' . ($price['tax_behavior'] ?? 'unspecified') . '", debe ser "inclusive" (IVA incluido)';
                        if ($strictTax) {
                            $problems[] = $tax;
                        } else {
                            $warnings[] = "$label ($var): $tax";
                        }
                    }
                }
            }
            foreach ($problems as $p) {
                $errors[] = "$label ($var): $p";
            }
            $rows[] = [$label, $var, $shown, self::euros($expected) . ' EUR', $problems === [] ? 'OK' : 'NO CUADRA'];
        }

        $modes = array_unique($modes);
        $io->title('Precios de Stripe frente a los de la app');
        $io->text('Entorno: ' . $this->environment . ($modes !== [] ? ' · Stripe en modo ' . implode(' y ', $modes) : ''));
        $io->table(['Plan', 'Variable', 'Stripe', 'La app muestra', 'Estado'], $rows);

        foreach ($warnings as $w) {
            $io->warning('Aviso (en producción sería un error): ' . $w);
        }
        if ($errors !== []) {
            $io->error(array_merge(['Los precios de Stripe no cuadran con la app:'], $errors, [
                'Arréglalo antes de publicar: apunta la variable al precio correcto de Stripe o cambia el importe en config/services.yaml (app.billing_prices / app.reseller_prices).',
            ]));

            return Command::FAILURE;
        }

        $io->success('Los 6 precios de Stripe cuadran con los que muestra la app.');

        return Command::SUCCESS;
    }

    private static function planName(string $interval): string
    {
        return $interval === 'yearly' ? 'anual' : 'mensual';
    }

    /** 3999 → "39,99"; 4000 → "40,00". */
    private static function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
