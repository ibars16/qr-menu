<?php

namespace App\Repository;

use App\Entity\Allergen;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AllergenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Allergen::class);
    }

    /** @return Allergen[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, Allergen> code => Allergen */
    public function findAllIndexedByCode(): array
    {
        $indexed = [];
        foreach ($this->findAllOrdered() as $allergen) {
            $indexed[$allergen->getCode()] = $allergen;
        }

        return $indexed;
    }
}
