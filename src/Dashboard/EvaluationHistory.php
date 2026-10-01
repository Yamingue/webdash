<?php

namespace App\Dashboard;

use App\Entity\Domain;
use App\Repository\EvaluationRepository;

/**
 * Historique paginé des évaluations d'un domaine : on pagine par semaines renseignées
 * (une semaine = un groupe complet de KPI), de la plus récente à la plus ancienne.
 * Seules les évaluations soumises ou validées sont listées (comme pour les scores).
 */
final class EvaluationHistory
{
    public const PER_PAGE = 4;

    public function __construct(private readonly EvaluationRepository $evaluations)
    {
    }

    /** Une page hors limites est ramenée à la première ou à la dernière page. */
    public function page(Domain $domain, int $page = 1, int $perPage = self::PER_PAGE): HistoryPage
    {
        $perPage = max(1, $perPage);
        $total = $this->evaluations->countReportableWeeksForDomain($domain);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $weeks = $this->evaluations->findReportableWeeksForDomain($domain, $perPage, ($page - 1) * $perPage);

        $byWeek = [];
        foreach ($this->evaluations->findReportableForDomainWeeks($domain, $weeks) as $evaluation) {
            $byWeek[$evaluation->getWeekStart()->format('Y-m-d')][] = new KpiLine($evaluation->getKpi(), $evaluation);
        }

        $groups = array_map(
            static fn (\DateTimeImmutable $week): WeekLines => new WeekLines($week, $byWeek[$week->format('Y-m-d')] ?? []),
            $weeks,
        );

        return new HistoryPage($groups, $page, $pages, $total, $perPage);
    }
}
