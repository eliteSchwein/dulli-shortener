<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PhishingDomain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PhishingDomain>
 */
class PhishingDomainRepository extends ServiceEntityRepository
{
    private const int INSERT_BATCH_SIZE = 1000;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhishingDomain::class);
    }

    public function isEmpty(): bool
    {
        return $this->getEntityManager()->getConnection()
            ->fetchOne('SELECT 1 FROM phishing_domain LIMIT 1') === false;
    }

    /**
     * @param list<string> $hosts
     */
    public function containsAny(array $hosts): bool
    {
        if ($hosts === []) {
            return false;
        }

        return $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM phishing_domain WHERE domain IN (?) LIMIT 1',
            [$hosts],
            [ArrayParameterType::STRING],
        ) !== false;
    }

    /**
     * Atomically replaces the whole list.
     *
     * @param list<string> $domains unique, normalized hostnames
     */
    public function replaceAll(array $domains): void
    {
        $connection = $this->getEntityManager()->getConnection();

        $connection->transactional(function () use ($connection, $domains): void {
            $connection->executeStatement('DELETE FROM phishing_domain');

            foreach (array_chunk($domains, self::INSERT_BATCH_SIZE) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?)'));
                $connection->executeStatement("INSERT INTO phishing_domain (domain) VALUES {$placeholders}", $chunk);
            }
        });
    }
}
