<?php

namespace App\Report;

use App\Entity\Evaluation;

final readonly class ReportResult
{
    /**
     * @param list<Evaluation> $rows
     * @param list<DomainStat> $stats
     */
    public function __construct(
        public ReportCriteria $criteria,
        public array $rows,
        public array $stats,
    ) {
    }
}
