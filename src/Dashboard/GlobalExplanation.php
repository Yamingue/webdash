<?php

namespace App\Dashboard;

/** Le détail du calcul de l'atteinte globale pour une semaine. */
final readonly class GlobalExplanation
{
    /**
     * @param list<DomainSummary> $counted   domaines qui ont un score (entrent dans la moyenne)
     * @param list<DomainSummary> $withoutData domaines sans aucune évaluation soumise cette semaine
     */
    public function __construct(
        public \DateTimeImmutable $week,
        public array $counted,
        public array $withoutData,
        public ?float $score,
    ) {
    }
}
