<?php

namespace App\Dashboard;

/** Une page de l'historique des évaluations d'un domaine, regroupées par semaine. */
final readonly class HistoryPage
{
    /** @param list<WeekLines> $groups semaines de la page, la plus récente d'abord */
    public function __construct(
        public array $groups,
        public int $page,
        public int $pages,
        public int $totalWeeks,
        public int $perPage,
    ) {
    }

    /** Rang (1-based) de la première semaine affichée, 0 s'il n'y a rien. */
    public function firstWeekNumber(): int
    {
        return [] === $this->groups ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function lastWeekNumber(): int
    {
        return [] === $this->groups ? 0 : $this->firstWeekNumber() + \count($this->groups) - 1;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }
}
