<?php

namespace App\Dashboard;

/**
 * Répartition des KPI actifs d'une semaine par statut (rouge / ambre / vert / non renseigné), avec le détail
 * de chaque KPI. Construit à partir des mêmes résumés que le reste du tableau de bord : les chiffres concordent.
 */
final readonly class StatusBoard
{
    /** @param list<StatusRow> $rows tous les KPI actifs, les plus urgents d'abord */
    public function __construct(public array $rows)
    {
    }

    /** @param list<DomainSummary> $summaries */
    public static function fromSummaries(array $summaries): self
    {
        $rows = [];
        foreach ($summaries as $summary) {
            $lastKnown = [];
            foreach ($summary->latest as $line) {
                $lastKnown[$line->kpi->getId()] = $line->evaluation;
            }

            foreach ($summary->lines as $line) {
                $rows[] = new StatusRow(
                    $summary->domain,
                    $line,
                    KpiStatus::fromRate($line->achievement()),
                    $lastKnown[$line->kpi->getId()] ?? null,
                );
            }
        }

        // Tri stable : urgence, puis taux croissant, puis ordre d'origine (domaines et KPI dans l'ordre d'affichage).
        $indexed = array_map(null, $rows, array_keys($rows));
        usort($indexed, static function (array $a, array $b): int {
            /** @var StatusRow $x */
            /** @var StatusRow $y */
            [$x, $i] = $a;
            [$y, $j] = $b;

            return [$x->status->priority(), $x->rate() ?? 0.0, $i] <=> [$y->status->priority(), $y->rate() ?? 0.0, $j];
        });

        return new self(array_map(static fn (array $pair): StatusRow => $pair[0], $indexed));
    }

    public function total(): int
    {
        return \count($this->rows);
    }

    public function count(KpiStatus $status): int
    {
        return \count(array_filter($this->rows, static fn (StatusRow $r): bool => $r->status === $status));
    }

    /**
     * Nombre de KPI du statut par domaine, dans l'ordre des domaines (les domaines à 0 sont omis).
     *
     * @return array<string, int> nom du domaine => nombre
     */
    public function breakdown(KpiStatus $status): array
    {
        $byDomain = [];
        foreach ($this->rows as $row) {
            if ($row->status === $status) {
                $name = $row->domain->getName();
                $byDomain[$name] = ($byDomain[$name] ?? 0) + 1;
            }
        }

        return $byDomain;
    }
}
