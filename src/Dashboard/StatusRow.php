<?php

namespace App\Dashboard;

use App\Entity\Domain;
use App\Entity\Evaluation;

/** Un KPI actif et son statut pour la semaine choisie. */
final readonly class StatusRow
{
    public function __construct(
        public Domain $domain,
        /** Le KPI et son évaluation soumise/validée de la semaine (null si aucune). */
        public KpiLine $line,
        public KpiStatus $status,
        /** Dernière évaluation connue du KPI (jusqu'à la semaine choisie), utile quand il n'est pas renseigné. */
        public ?Evaluation $lastKnown,
    ) {
    }

    public function rate(): ?float
    {
        return $this->line->achievement();
    }

    /** Pourquoi le KPI n'est pas renseigné (null s'il l'est). */
    public function missingReason(): ?string
    {
        if (KpiStatus::Missing !== $this->status) {
            return null;
        }
        if (null !== $this->line->evaluation) {
            return 'Objectif à 0 : taux non calculable';
        }
        if (null === $this->lastKnown) {
            return 'Aucune évaluation soumise ou validée';
        }

        $rate = $this->lastKnown->getAchievement();

        return \sprintf(
            'Dernière valeur%s (semaine du %s)',
            null === $rate ? '' : ' : '.rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.').' %',
            $this->lastKnown->getWeekStart()->format('d/m'),
        );
    }
}
