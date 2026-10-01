<?php

namespace App\Dashboard;

use App\Entity\Kpi;

/** Un KPI actif qui n'entre pas dans le score de la semaine, avec la raison. */
final readonly class ExcludedKpi
{
    public function __construct(
        public Kpi $kpi,
        public string $reason,
    ) {
    }
}
