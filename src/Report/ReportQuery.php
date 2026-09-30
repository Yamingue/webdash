<?php

namespace App\Report;

use Symfony\Component\Validator\Constraints as Assert;

/** Paramètres bruts du formulaire de filtres (query string), résolus en ReportCriteria. */
final readonly class ReportQuery
{
    public function __construct(
        /** Une date (Y-m-d) de la première semaine ; vide = 7 semaines avant la fin. */
        public ?string $from = null,
        /** Une date (Y-m-d) de la dernière semaine ; vide = semaine courante. */
        public ?string $to = null,
        /** Identifiant d'un domaine ; vide = tous les domaines accessibles. */
        public ?string $domain = null,
        #[Assert\Choice(choices: ReportCriteria::STATUSES)]
        public string $status = ReportCriteria::STATUS_REPORTABLE,
    ) {
    }
}
