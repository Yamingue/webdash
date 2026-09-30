<?php

namespace App\Dashboard;

use App\Entity\Evaluation;
use App\Entity\Kpi;

/** Un KPI actif et son évaluation de la semaine (null si aucune saisie soumise/validée). */
final readonly class KpiLine
{
    public function __construct(
        public Kpi $kpi,
        public ?Evaluation $evaluation,
    ) {
    }

    public function achievement(): ?float
    {
        return $this->evaluation?->getAchievement();
    }
}
