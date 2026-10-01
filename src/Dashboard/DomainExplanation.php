<?php

namespace App\Dashboard;

use App\Entity\Domain;

/** Le détail du calcul du score d'un domaine pour une semaine. */
final readonly class DomainExplanation
{
    /**
     * @param list<ExplainRow>  $rows     KPI pris en compte
     * @param list<ExcludedKpi> $excluded KPI actifs écartés, avec la raison
     */
    public function __construct(
        public Domain $domain,
        public \DateTimeImmutable $week,
        public array $rows,
        public array $excluded,
        /** Le score affiché partout ailleurs (même fonction de calcul que le tableau de bord). */
        public ?float $score,
    ) {
    }

    /** Σ(poids × taux retenu) */
    public function weightedSum(): float
    {
        return array_sum(array_map(static fn (ExplainRow $r): float => $r->contribution(), $this->rows));
    }

    /** Σ(poids) */
    public function totalWeight(): float
    {
        return array_sum(array_map(static fn (ExplainRow $r): float => $r->weight, $this->rows));
    }
}
