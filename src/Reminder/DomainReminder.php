<?php

namespace App\Reminder;

use App\Entity\Domain;
use App\Entity\Kpi;

/** Ce qu'il reste à faire pour une personne dans un domaine. */
final readonly class DomainReminder
{
    public function __construct(
        public Domain $domain,
        /** KPI actifs sans évaluation soumise ou validée pour la semaine. @var list<Kpi> */
        public array $missing,
        /** Évaluations soumises en attente de validation (null si la personne n'est pas responsable du domaine). */
        public ?int $pending,
    ) {
    }
}
