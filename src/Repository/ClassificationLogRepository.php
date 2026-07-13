<?php

namespace App\Repository;

use App\Entity\ClassificationLog;
use App\Enum\ClassificationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ClassificationLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClassificationLog::class);
    }

    /** @return array<int, true> subjectId => true, for every subject already logged for this classification type */
    public function findLoggedSubjectIds(string $classificationType): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT l.subjectId')
            ->andWhere('l.classificationType = :type')
            ->setParameter('type', $classificationType)
            ->getQuery()
            ->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row['subjectId']] = true;
        }

        return $ids;
    }

    /** @return ClassificationLog[] */
    public function findByStatus(string $classificationType, ClassificationStatus $status): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.classificationType = :type')
            ->andWhere('l.status = :status')
            ->setParameter('type', $classificationType)
            ->setParameter('status', $status)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, int> status value => count */
    public function countByStatus(string $classificationType): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('l.status AS status', 'COUNT(l.id) AS c')
            ->andWhere('l.classificationType = :type')
            ->setParameter('type', $classificationType)
            ->groupBy('l.status')
            ->getQuery()
            ->getScalarResult();

        $counts = [];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof ClassificationStatus ? $row['status']->value : $row['status'];
            $counts[$status] = (int) $row['c'];
        }

        return $counts;
    }
}
