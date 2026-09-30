<?php

namespace App\Dashboard;

use App\Entity\Kpi;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/** Courbe d'évolution valeur / objectif d'un KPI. */
final class KpiChartBuilder
{
    private const VALUE_COLOR = '#4f46e5';
    private const TARGET_COLOR = '#d97706';

    public function __construct(private readonly ChartBuilderInterface $chartBuilder)
    {
    }

    /**
     * @param list<\DateTimeImmutable>                          $weeks
     * @param array{score: list<?float>, target: list<?float>} $series
     *
     * @return ?Chart null s'il n'y a aucune valeur sur la période
     */
    public function build(Kpi $kpi, array $weeks, array $series): ?Chart
    {
        if ([] === array_filter($series['score'], static fn (?float $v): bool => null !== $v)) {
            return null;
        }

        $unit = $kpi->getUnit();

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(static fn (\DateTimeImmutable $w): string => $w->format('d/m'), $weeks),
            'datasets' => [
                [
                    'label' => 'Valeur',
                    'data' => $series['score'],
                    'borderColor' => self::VALUE_COLOR,
                    'backgroundColor' => self::VALUE_COLOR,
                    'tension' => 0.25,
                    'spanGaps' => true,
                    'pointRadius' => 6,
                    'pointHoverRadius' => 8,
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'clip' => false,
                ],
                [
                    'label' => 'Objectif',
                    'data' => $series['target'],
                    'borderColor' => self::TARGET_COLOR,
                    'backgroundColor' => self::TARGET_COLOR,
                    'borderDash' => [6, 6],
                    'borderWidth' => 2,
                    'spanGaps' => true,
                    'pointRadius' => 5,
                    'pointBorderColor' => '#ffffff',
                    'pointBorderWidth' => 2,
                    'clip' => false,
                    'pointStyle' => 'rectRot',
                ],
            ],
        ]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => ['offset' => true],
                'y' => [
                    'beginAtZero' => true,
                    'title' => ['display' => null !== $unit && '' !== $unit, 'text' => $unit ?? ''],
                ],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ]);

        return $chart;
    }
}
