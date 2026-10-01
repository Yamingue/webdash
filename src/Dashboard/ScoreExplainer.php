<?php

namespace App\Dashboard;

use App\Entity\Domain;
use App\Entity\User;
use App\Enum\EvaluationStatus;
use App\Repository\EvaluationRepository;

/**
 * Détaille comment un score est obtenu, avec les mêmes règles et la même fonction de moyenne
 * que le tableau de bord (DashboardProvider::weightedAverage) : les deux ne peuvent pas diverger.
 */
final class ScoreExplainer
{
    public function __construct(
        private readonly EvaluationRepository $evaluations,
        private readonly DashboardProvider $provider,
    ) {
    }

    public function forDomain(Domain $domain, \DateTimeImmutable $week): DomainExplanation
    {
        $byKpi = [];
        foreach ($this->evaluations->findForDomainBetween($domain, $week, $week) as $evaluation) {
            $byKpi[$evaluation->getKpi()->getId()] = $evaluation;
        }

        $rows = $excluded = $counted = [];
        foreach ($domain->getKpis() as $kpi) {
            if (!$kpi->isActive()) {
                continue;
            }

            $evaluation = $byKpi[$kpi->getId()] ?? null;
            if (null === $evaluation) {
                $excluded[] = new ExcludedKpi($kpi, 'Aucune évaluation pour cette semaine.');
                continue;
            }
            if (EvaluationStatus::Draft === $evaluation->getStatus()) {
                $excluded[] = new ExcludedKpi($kpi, 'Évaluation en brouillon : pas encore soumise.');
                continue;
            }

            $rate = $evaluation->getAchievement();
            if (null === $rate) {
                $excluded[] = new ExcludedKpi($kpi, 'Objectif à 0 : le taux ne peut pas être calculé.');
                continue;
            }

            $rows[] = new ExplainRow($kpi, $evaluation, $rate, min($rate, DashboardProvider::RATE_CAP), $evaluation->getWeight());
            $counted[] = $evaluation;
        }

        return new DomainExplanation($domain, $week, $rows, $excluded, DashboardProvider::weightedAverage($counted));
    }

    public function forUser(User $user, \DateTimeImmutable $week): GlobalExplanation
    {
        $summaries = $this->provider->summaries($user, $week);

        return new GlobalExplanation(
            $week,
            array_values(array_filter($summaries, static fn (DomainSummary $s): bool => null !== $s->average)),
            array_values(array_filter($summaries, static fn (DomainSummary $s): bool => null === $s->average)),
            DashboardProvider::overallAverage($summaries),
        );
    }
}
