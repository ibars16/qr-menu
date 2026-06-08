<?php

namespace App\Service;

use App\Entity\ExchangeRate;
use App\Repository\ExchangeRateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRateUpdater
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private EntityManagerInterface $entityManager,
        private ExchangeRateRepository $exchangeRateRepository,
        private string $exchangeApiKey,
    ) {}

    public function updateRates(): void
    {
        $response = $this->httpClient->request(
            'GET',
            'https://api.exchangeratesapi.io/v1/latest?access_key='.$this->exchangeApiKey
        );

        $data = $response->toArray();

        if (!isset($data['rates'])) {
            throw new \RuntimeException('Exchange API error.');
        }

        $baseCurrency = $data['base']; // EUR

        foreach ($data['rates'] as $currency => $rate) {

            if ($currency === $baseCurrency) {
                continue;
            }

            $exchangeRate =
                $this->exchangeRateRepository->findOneBy([
                    'baseCurrency' => $baseCurrency,
                    'targetCurrency' => $currency,
                ]);

            if (!$exchangeRate) {

                $exchangeRate = new ExchangeRate();

                $exchangeRate
                    ->setBaseCurrency($baseCurrency)
                    ->setTargetCurrency($currency);
            }

            $exchangeRate
                ->setRate((string) $rate)
                ->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($exchangeRate);
        }

        $this->entityManager->flush();
    }
}
