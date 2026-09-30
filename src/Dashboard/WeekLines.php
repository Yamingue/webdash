<?php

namespace App\Dashboard;

/** Les KPI d'un domaine pour une semaine donnée de l'historique. */
final readonly class WeekLines
{
    /**
     * @param list<KpiLine> $lines tous les KPI actifs du domaine ; sans évaluation = non saisi cette semaine
     */
    public function __construct(
        public \DateTimeImmutable $week,
        public array $lines,
    ) {
    }
}
