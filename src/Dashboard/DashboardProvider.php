<?php

namespace App\Dashboard;

use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Repository\DomainRepository;
use App\Repository\EvaluationRepository;

/**
 * Indicateurs du tableau de bord. Seules les évaluations soumises ou validées comptent ;
 * le taux d'atteinte d'un domaine est la moyenne des taux de ses KPI évalués.
 */
final class DashboardProvider
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly EvaluationRepository $evaluations,
    ) {
    }

    /** Nombre maximum de semaines renseignées listées dans l'onglet « Tous » d'une carte. */
    public const HISTORY_WEEKS = 4;
    /** Profondeur de recherche (en semaines avant la semaine choisie) des dernières valeurs et de l'historique. */
    public const LOOKBACK_WEEKS = 26;
    /** Plafond (%) appliqué à chaque taux dans les moyennes pondérées. */
    public const RATE_CAP = 120.0;

    /** @return list<DomainSummary> un résumé par domaine visible par l'utilisateur */
    public function summaries(User $user, \DateTimeImmutable $week): array
    {
        $domains = $this->domains->findVisibleTo($user);
        $weekKey = $week->format('Y-m-d');
        $previousKey = $week->modify('-1 week')->format('Y-m-d');
        $from = $week->modify(\sprintf('-%d weeks', self::LOOKBACK_WEEKS));

        // domaine => semaine (Y-m-d) => KPI => évaluation, sur la période de recherche, KPI inactifs exclus
        $byDomain = [];
        foreach ($this->evaluations->findReportable($domains, $from, $week) as $evaluation) {
            if ($evaluation->getKpi()->isActive()) {
                $byDomain[$evaluation->getKpi()->getDomain()->getId()][$evaluation->getWeekStart()->format('Y-m-d')][$evaluation->getKpi()->getId()] = $evaluation;
            }
        }

        $summaries = [];
        foreach ($domains as $domain) {
            $weeks = $byDomain[$domain->getId()] ?? [];
            $activeKpis = array_values(array_filter($domain->getKpis()->toArray(), static fn (Kpi $k): bool => $k->isActive()));

            $lines = array_map(static fn (Kpi $kpi): KpiLine => new KpiLine($kpi, $weeks[$weekKey][$kpi->getId()] ?? null), $activeKpis);

            krsort($weeks); // semaines les plus récentes d'abord

            // Dernière valeur saisie de chaque KPI, même ancienne.
            $latest = [];
            foreach ($activeKpis as $kpi) {
                foreach ($weeks as $evaluations) {
                    if (isset($evaluations[$kpi->getId()])) {
                        $latest[] = new KpiLine($kpi, $evaluations[$kpi->getId()]);
                        break;
                    }
                }
            }

            // Historique : les dernières semaines renseignées, avec tous les KPI actifs (N/D si non saisi).
            $history = [];
            foreach (\array_slice($weeks, 0, self::HISTORY_WEEKS, true) as $key => $evaluations) {
                $history[] = new WeekLines(
                    new \DateTimeImmutable($key),
                    array_map(static fn (Kpi $kpi): KpiLine => new KpiLine($kpi, $evaluations[$kpi->getId()] ?? null), $activeKpis),
                );
            }

            $summaries[] = new DomainSummary(
                $domain,
                $lines,
                self::weightedAverage(array_filter(array_map(static fn (KpiLine $l): ?Evaluation => $l->evaluation, $lines))),
                self::weightedAverage(array_values($weeks[$previousKey] ?? [])),
                $this->pendingFor($user, $domain),
                $latest,
                $history,
            );
        }

        return $summaries;
    }

    /**
     * Taux d'atteinte moyen par domaine sur plusieurs semaines (pour le graphique).
     *
     * @param list<\DateTimeImmutable> $weeks
     *
     * @return array<int, array<string, ?float>> id de domaine => (lundi Y-m-d => moyenne %)
     */
    public function series(User $user, array $weeks): array
    {
        if ([] === $weeks) {
            return [];
        }

        $domains = $this->domains->findVisibleTo($user);
        $byWeek = $this->groupByWeekAndKpi($this->evaluations->findReportable($domains, $weeks[0], end($weeks)));

        $series = [];
        foreach ($domains as $domain) {
            foreach ($weeks as $week) {
                $key = $week->format('Y-m-d');
                $series[$domain->getId()][$key] = self::weightedAverage(array_filter(
                    $byWeek[$key] ?? [],
                    static fn (Evaluation $e): bool => $e->getKpi()->getDomain() === $domain && $e->getKpi()->isActive(),
                ));
            }
        }

        return $series;
    }

    /**
     * Valeur et objectif de chaque KPI actif d'un domaine, semaine par semaine (évaluations soumises ou validées).
     * L'objectif est celui figé dans chaque évaluation : il reflète donc ses changements dans le temps.
     *
     * @param list<\DateTimeImmutable> $weeks
     *
     * @return array<int, array{score: list<?float>, target: list<?float>}> id de KPI => séries alignées sur $weeks
     */
    public function kpiSeries(Domain $domain, array $weeks): array
    {
        if ([] === $weeks) {
            return [];
        }

        $byWeek = $this->groupByWeekAndKpi($this->evaluations->findReportable([$domain], $weeks[0], end($weeks)));

        $series = [];
        foreach ($domain->getKpis() as $kpi) {
            if (!$kpi->isActive()) {
                continue;
            }
            $scores = $targets = [];
            foreach ($weeks as $week) {
                $evaluation = $byWeek[$week->format('Y-m-d')][$kpi->getId()] ?? null;
                $scores[] = $evaluation?->getScore();
                $targets[] = null === $evaluation?->getScore() ? null : $evaluation->getTarget();
            }
            $series[$kpi->getId()] = ['score' => $scores, 'target' => $targets];
        }

        return $series;
    }

    /** Moyenne globale des domaines ayant des données (%), null si aucune. */
    public static function overallAverage(array $summaries): ?float
    {
        return self::average(array_map(static fn (DomainSummary $s): ?float => $s->average, $summaries));
    }

    /**
     * Moyenne pondérée des taux d'atteinte : Σ(poids × taux) ÷ Σ(poids), sur les évaluations qui ont un taux.
     * - Le poids est celui figé dans chaque évaluation (changer le poids d'un KPI ne réécrit pas l'historique).
     * - Un KPI sans évaluation n'entre pas dans le calcul : le domaine est jugé sur ce qui est saisi.
     * - Chaque taux est plafonné à RATE_CAP dans la moyenne, pour qu'un KPI très au-dessus de l'objectif
     *   ne masque pas un KPI en retard. L'affichage d'un KPI, lui, garde son vrai taux.
     *
     * @param iterable<Evaluation> $evaluations
     */
    public static function weightedAverage(iterable $evaluations): ?float
    {
        $sum = $weights = 0.0;
        foreach ($evaluations as $evaluation) {
            $rate = $evaluation->getAchievement();
            if (null === $rate) {
                continue;
            }
            $sum += min($rate, self::RATE_CAP) * $evaluation->getWeight();
            $weights += $evaluation->getWeight();
        }

        return $weights > 0 ? round($sum / $weights, 1) : null;
    }

    /** Moyenne simple, en ignorant les valeurs nulles (ex. moyenne des scores de domaines). @param iterable<?float> $values */
    public static function average(iterable $values): ?float
    {
        $values = array_filter([...$values], static fn (?float $v): bool => null !== $v);

        return [] === $values ? null : round(array_sum($values) / \count($values), 1);
    }

    private function pendingFor(User $user, Domain $domain): ?int
    {
        $isManager = $user->isAdmin() || MembershipRole::Manager === $user->getRoleIn($domain);

        return $isManager ? $this->evaluations->countSubmittedForDomain($domain) : null;
    }

    /**
     * @param list<Evaluation> $evaluations
     *
     * @return array<string, array<int, Evaluation>> lundi Y-m-d => id de KPI => évaluation
     */
    private function groupByWeekAndKpi(array $evaluations): array
    {
        $grouped = [];
        foreach ($evaluations as $evaluation) {
            $grouped[$evaluation->getWeekStart()->format('Y-m-d')][$evaluation->getKpi()->getId()] = $evaluation;
        }

        return $grouped;
    }
}
