<?php

namespace App\Repository;

use App\Entity\Url;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Url>
 */
class UrlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Url::class);
    }

    public function removeUrl(Url $url, bool $flush = true): void
    {
        $this->getEntityManager()->remove($url);

        if($flush) $this->getEntityManager()->flush();
    }

    public function updateUrl(Url $url, bool $flush = true): void
    {
        $this->getEntityManager()->persist($url);

        if($flush) $this->getEntityManager()->flush();
    }

    public function purgeExpired(): void
    {
        $date = new DateTime();
        $this->createQueryBuilder('u')
            ->where('u.endDate < :date')
            ->andWhere('u.endDate IS NOT NULL')
            ->setParameter('date', $date)
            ->delete()
            ->getQuery()
            ->getResult();
    }
}
