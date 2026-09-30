<?php

namespace App\Report;

use App\Entity\Domain;

/** Statistiques d'un domaine sur la période du rapport. */
final readonly class DomainStat
{
    public function __construct(
        public Domain $domain,
        public int $count,
        public int $validatedCount,
        /** Moyenne des taux d'atteinte (%), null si aucun calculable. */
        public ?float $average,
        /** Part des évaluations à l'objectif (≥ 100 %), en %, null si aucune calculable. */
        public ?float $atTargetRate,
    ) {
    }
}
