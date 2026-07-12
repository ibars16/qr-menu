<?php

namespace App\Service;

use App\Repository\ExchangeRateRepository;

class CurrencyConverter
{
    public function __construct(
        private ExchangeRateRepository $repository
    ) {}

    public function convert(
        int $amountInCents,
        string $fromCurrency,
        string $toCurrency
    ): int
    {
        if ($fromCurrency === $toCurrency) {
            return $amountInCents;
        }

        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        // Rates are stored as EUR → X (ExchangeRateUpdater never stores a
        // EUR → EUR row since the rates API already omits it), so treat EUR
        // itself as an implicit rate of 1.0 rather than requiring a DB row.
        $fromRate = $fromCurrency === 'EUR' ? 1.0 : $this->repository->findOneBy([
            'baseCurrency' => 'EUR',
            'targetCurrency' => $fromCurrency,
        ])?->getRate();

        $toRate = $toCurrency === 'EUR' ? 1.0 : $this->repository->findOneBy([
            'baseCurrency' => 'EUR',
            'targetCurrency' => $toCurrency,
        ])?->getRate();

        if ($fromRate === null || $toRate === null) {
            return $amountInCents;
        }

        $amountInEur =
            $amountInCents / (float) $fromRate;

        $converted =
            $amountInEur * (float) $toRate;

        return (int) round($converted);
    }
}
