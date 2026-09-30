<?php

namespace App\Repository;

use App\Audit\AuditAction;
use App\Entity\AuditLog;
use App\Entity\Domain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLog> */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Dernières lignes du journal, les plus récentes d'abord.
     *
     * @param list<Domain>|null $domains domaines visibles ; null = tous (y compris les lignes sans domaine)
     *
     * @return list<AuditLog>
     */
    public function findLatest(?array $domains, ?Domain $domain, ?AuditAction $action, int $limit = 200): array
    {
        if (null !== $domains && [] === $domains) {
            return [];
        }

        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.domain', 'd')
            ->addSelect('d')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit);

        if (null !== $domain) {
            $qb->andWhere('l.domain = :domain')->setParameter('domain', $domain);
        } elseif (null !== $domains) {
            $qb->andWhere('l.domain IN (:domains)')->setParameter('domains', $domains);
        }
        if (null !== $action) {
            $qb->andWhere('l.action = :action')->setParameter('action', $action);
        }

        return $qb->getQuery()->getResult();
    }
}
