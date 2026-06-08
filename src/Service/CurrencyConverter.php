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

        $eurToFrom = $this->repository->findOneBy([
            'baseCurrency' => 'EUR',
            'targetCurrency' => $fromCurrency,
        ]);

        $eurToTo = $this->repository->findOneBy([
            'baseCurrency' => 'EUR',
            'targetCurrency' => $toCurrency,
        ]);

        if (!$eurToFrom || !$eurToTo) {
            return $amountInCents;
        }

        $amountInEur =
            $amountInCents / (float) $eurToFrom->getRate();

        $converted =
            $amountInEur * (float) $eurToTo->getRate();

        return (int) round($converted);
    }
}
