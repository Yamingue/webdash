<?php

namespace App\Dashboard;

use App\Entity\Evaluation;
use App\Entity\Kpi;

/** Une ligne du détail du calcul : un KPI qui entre dans le score du domaine. */
final readonly class ExplainRow
{
    public function __construct(
        public Kpi $kpi,
        public Evaluation $evaluation,
        /** Taux d'atteinte réel du KPI (%). */
        public float $rate,
        /** Taux retenu dans la moyenne : plafonné à DashboardProvider::RATE_CAP. */
        public float $counted,
        public float $weight,
    ) {
    }

    public function isCapped(): bool
    {
        return $this->counted < $this->rate;
    }

    /** Poids × taux retenu : la part de ce KPI dans le numérateur de la moyenne pondérée. */
    public function contribution(): float
    {
        return $this->weight * $this->counted;
    }
}
