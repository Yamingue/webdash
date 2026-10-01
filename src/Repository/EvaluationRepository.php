<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Enum\EvaluationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Evaluation> */
class EvaluationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Evaluation::class);
    }

    /**
     * Évaluations d'un domaine pour une semaine, indexées par id de KPI.
     *
     * @return array<int, Evaluation>
     */
    public function findForDomainWeek(Domain $domain, \DateTimeImmutable $weekStart): array
    {
        $indexed = [];
        foreach ($this->findForDomainBetween($domain, $weekStart, $weekStart) as $evaluation) {
            $indexed[$evaluation->getKpi()->getId()] = $evaluation;
        }

        return $indexed;
    }

    /** Nombre de semaines distinctes ayant au moins une évaluation soumise ou validée dans le domaine. */
    public function countReportableWeeksForDomain(Domain $domain): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.weekStart)')
            ->join('e.kpi', 'k')
            ->andWhere('k.domain = :domain')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('domain', $domain)
            ->setParameter('statuses', [EvaluationStatus::Submitted, EvaluationStatus::Validated])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Une page de semaines renseignées (évaluations soumises ou validées), la plus récente d'abord.
     *
     * @return list<\DateTimeImmutable> lundis
     */
    public function findReportableWeeksForDomain(Domain $domain, int $limit, int $offset): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.weekStart AS week')
            ->join('e.kpi', 'k')
            ->andWhere('k.domain = :domain')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('domain', $domain)
            ->setParameter('statuses', [EvaluationStatus::Submitted, EvaluationStatus::Validated])
            ->groupBy('e.weekStart')
            ->orderBy('e.weekStart', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(
            static fn (array $row): \DateTimeImmutable => $row['week'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($row['week'])
                : new \DateTimeImmutable((string) $row['week']),
            $rows,
        );
    }

    /**
     * Évaluations soumises ou validées du domaine pour ces semaines, plus récente semaine d'abord,
     * puis dans l'ordre d'affichage des KPI.
     *
     * @param list<\DateTimeImmutable> $weeks
     *
     * @return list<Evaluation>
     */
    public function findReportableForDomainWeeks(Domain $domain, array $weeks): array
    {
        if ([] === $weeks) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->join('e.kpi', 'k')
            ->addSelect('k')
            ->andWhere('k.domain = :domain')
            ->andWhere('e.status IN (:statuses)')
            ->andWhere('e.weekStart IN (:weeks)')
            ->setParameter('domain', $domain)
            ->setParameter('statuses', [EvaluationStatus::Submitted, EvaluationStatus::Validated])
            ->setParameter('weeks', array_map(static fn (\DateTimeImmutable $w): string => $w->format('Y-m-d'), $weeks))
            ->orderBy('e.weekStart', 'DESC')
            ->addOrderBy('k.position', 'ASC')
            ->addOrderBy('k.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Evaluation> */
    public function findForDomainBetween(Domain $domain, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.kpi', 's')
            ->andWhere('s.domain = :domain')
            ->andWhere('e.weekStart BETWEEN :from AND :to')
            ->setParameter('domain', $domain)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Évaluations prises en compte dans les indicateurs : soumises ou validées (jamais les brouillons).
     *
     * @param list<Domain> $domains
     *
     * @return list<Evaluation>
     */
    public function findReportable(array $domains, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $domains) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->join('e.kpi', 'k')
            ->addSelect('k')
            ->andWhere('k.domain IN (:domains)')
            ->andWhere('e.weekStart BETWEEN :from AND :to')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('domains', $domains)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setParameter('statuses', [EvaluationStatus::Submitted, EvaluationStatus::Validated])
            ->getQuery()
            ->getResult();
    }

    /**
     * Lignes d'un rapport : évaluations des domaines donnés entre deux lundis (inclus), avec auteur et valideur
     * chargés d'avance. Triées par semaine puis par ordre d'affichage du domaine et du KPI.
     *
     * @param list<Domain>           $domains
     * @param list<EvaluationStatus> $statuses
     *
     * @return list<Evaluation>
     */
    public function findForReport(array $domains, \DateTimeImmutable $from, \DateTimeImmutable $to, array $statuses): array
    {
        if ([] === $domains || [] === $statuses) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->join('e.kpi', 'k')
            ->join('k.domain', 'd')
            ->leftJoin('e.createdBy', 'cb')
            ->leftJoin('e.validatedBy', 'vb')
            ->addSelect('k', 'd', 'cb', 'vb')
            ->andWhere('k.domain IN (:domains)')
            ->andWhere('e.weekStart BETWEEN :from AND :to')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('domains', $domains)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->setParameter('statuses', $statuses)
            ->orderBy('e.weekStart', 'ASC')
            ->addOrderBy('d.position', 'ASC')
            ->addOrderBy('k.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Évaluations soumises en attente de validation, les plus anciennes semaines d'abord. */
    public function findSubmittedForDomain(Domain $domain): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.kpi', 'k')
            ->addSelect('k')
            ->andWhere('k.domain = :domain')
            ->andWhere('e.status = :status')
            ->setParameter('domain', $domain)
            ->setParameter('status', EvaluationStatus::Submitted)
            ->orderBy('e.weekStart', 'ASC')
            ->addOrderBy('k.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Évaluations validées les plus récentes d'un domaine (pour un éventuel déverrouillage).
     *
     * @return list<Evaluation>
     */
    public function findRecentlyValidatedForDomain(Domain $domain, int $limit = 30): array
    {
        return $this->createQueryBuilder('e')
            ->join('e.kpi', 'k')
            ->addSelect('k')
            ->andWhere('k.domain = :domain')
            ->andWhere('e.status = :status')
            ->setParameter('domain', $domain)
            ->setParameter('status', EvaluationStatus::Validated)
            ->orderBy('e.validatedAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countSubmittedForDomain(Domain $domain): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.kpi', 'k')
            ->andWhere('k.domain = :domain')
            ->andWhere('e.status = :status')
            ->setParameter('domain', $domain)
            ->setParameter('status', EvaluationStatus::Submitted)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countForDomain(Domain $domain): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->join('e.kpi', 's')
            ->andWhere('s.domain = :domain')
            ->setParameter('domain', $domain)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
