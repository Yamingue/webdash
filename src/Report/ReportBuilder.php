<?php

namespace App\Report;

use App\Dashboard\DashboardProvider;
use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\User;
use App\Enum\EvaluationStatus;
use App\Repository\DomainRepository;
use App\Repository\EvaluationRepository;
use App\Service\Week;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Construit les rapports, toujours limités aux domaines que l'utilisateur peut voir. */
final class ReportBuilder
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly EvaluationRepository $evaluations,
    ) {
    }

    /** @return list<Domain> */
    public function visibleDomains(User $user): array
    {
        return $this->domains->findVisibleTo($user);
    }

    /**
     * @throws AccessDeniedHttpException si le domaine demandé n'est pas accessible à l'utilisateur
     */
    public function criteria(User $user, ReportQuery $query, ?\DateTimeImmutable $now = null): ReportCriteria
    {
        $to = Week::resolve($this->nullIfBlank($query->to), $now);
        $from = null !== $this->nullIfBlank($query->from)
            ? Week::resolve($query->from, $now)
            : $to->modify('-7 weeks');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $clamped = false;
        $earliest = $to->modify(\sprintf('-%d weeks', ReportCriteria::MAX_WEEKS - 1));
        if ($from < $earliest) {
            $from = $earliest;
            $clamped = true;
        }

        return new ReportCriteria($from, $to, $this->resolveDomain($user, $query->domain), $query->status, $clamped);
    }

    public function build(User $user, ReportCriteria $criteria): ReportResult
    {
        $domains = null !== $criteria->domain ? [$criteria->domain] : $this->visibleDomains($user);
        $rows = $this->evaluations->findForReport($domains, $criteria->from, $criteria->to, $criteria->statuses());

        return new ReportResult($criteria, $rows, $this->stats($domains, $rows));
    }

    /**
     * @param list<Domain>     $domains
     * @param list<Evaluation> $rows
     *
     * @return list<DomainStat>
     */
    private function stats(array $domains, array $rows): array
    {
        $byDomain = [];
        foreach ($rows as $evaluation) {
            $byDomain[$evaluation->getKpi()->getDomain()->getId()][] = $evaluation;
        }

        $stats = [];
        foreach ($domains as $domain) {
            $evaluations = $byDomain[$domain->getId()] ?? [];
            $rates = array_values(array_filter(
                array_map(static fn (Evaluation $e): ?float => $e->getAchievement(), $evaluations),
                static fn (?float $rate): bool => null !== $rate,
            ));

            $stats[] = new DomainStat(
                $domain,
                \count($evaluations),
                \count(array_filter($evaluations, static fn (Evaluation $e): bool => EvaluationStatus::Validated === $e->getStatus())),
                DashboardProvider::weightedAverage($evaluations),
                [] === $rates ? null : round(\count(array_filter($rates, static fn (float $r): bool => $r >= 100)) / \count($rates) * 100, 1),
            );
        }

        return $stats;
    }

    private function resolveDomain(User $user, ?string $requested): ?Domain
    {
        $requested = $this->nullIfBlank($requested);
        if (null === $requested) {
            return null;
        }

        foreach ($this->visibleDomains($user) as $domain) {
            if ((string) $domain->getId() === $requested) {
                return $domain;
            }
        }

        throw new AccessDeniedHttpException('Domaine inaccessible.');
    }

    private function nullIfBlank(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
