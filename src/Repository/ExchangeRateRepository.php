<?php

namespace App\Repository;

use App\Entity\ExchangeRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ExchangeRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExchangeRate::class);
    }

    public function findRate(
        string $baseCurrency,
        string $targetCurrency
    ): ?ExchangeRate
    {
        return $this->findOneBy([
            'baseCurrency' => strtoupper($baseCurrency),
            'targetCurrency' => strtoupper($targetCurrency),
        ]);
    }

    public function getRateValue(
        string $baseCurrency,
        string $targetCurrency
    ): ?float
    {
        $rate = $this->findRate(
            $baseCurrency,
            $targetCurrency
        );

        return $rate ? (float) $rate->getRate() : null;
    }
}
