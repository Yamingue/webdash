<?php

namespace App\Dashboard;

use App\Entity\Domain;

/** Synthèse d'un domaine pour une semaine. */
final readonly class DomainSummary
{
    /**
     * @param list<KpiLine> $lines
     */
    public function __construct(
        public Domain $domain,
        public array $lines,
        /** Moyenne des taux d'atteinte des KPI évalués (%), null si aucun. */
        public ?float $average,
        public ?float $previousAverage,
        /** Nombre d'évaluations soumises en attente de validation (null si non responsable). */
        public ?int $pending,
        /** Dernière valeur saisie de chaque KPI (jusqu'à la semaine choisie), même ancienne ; KPI jamais saisis exclus. @var list<KpiLine> */
        public array $latest = [],
        /** Dernières semaines renseignées, plus récente d'abord, avec tous les KPI actifs de chaque semaine. @var list<WeekLines> */
        public array $history = [],
    ) {
    }

    /** Nombre de lignes (KPI × semaine) de l'historique : le « n » de l'onglet « Tous (n) ». */
    public function historyRowCount(): int
    {
        return array_sum(array_map(static fn (WeekLines $w): int => \count($w->lines), $this->history));
    }

    public function kpiCount(): int
    {
        return \count($this->lines);
    }

    /** KPI avec un taux d'atteinte calculable. */
    public function evaluatedCount(): int
    {
        return \count(array_filter($this->lines, static fn (KpiLine $l): bool => null !== $l->achievement()));
    }

    public function atTargetCount(): int
    {
        return \count(array_filter($this->lines, static fn (KpiLine $l): bool => ($l->achievement() ?? 0) >= 100));
    }

    public function missingCount(): int
    {
        return $this->kpiCount() - $this->evaluatedCount();
    }

    /** Écart en points par rapport à la semaine précédente. */
    public function trend(): ?float
    {
        if (null === $this->average || null === $this->previousAverage) {
            return null;
        }

        return round($this->average - $this->previousAverage, 1);
    }
}
