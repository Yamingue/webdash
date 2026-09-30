<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\DomainSummary;
use App\Entity\User;
use App\Service\Week;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

final class DashboardController extends AbstractController
{
    private const CHART_WEEKS = 8;

    /** Palette catégorielle lisible sur fond clair, une couleur par domaine. */
    private const PALETTE = ['#4f46e5', '#059669', '#d97706', '#e11d48', '#0284c7', '#7c3aed', '#0d9488', '#be185d'];

    #[Route('/', name: 'app_dashboard')]
    public function index(
        Request $request,
        DashboardProvider $provider,
        ChartBuilderInterface $chartBuilder,
        #[CurrentUser] User $user,
    ): Response {
        $week = Week::resolve($request->query->getString('week') ?: null);
        $summaries = $provider->summaries($user, $week);

        $evaluated = $total = $atTarget = 0;
        foreach ($summaries as $summary) {
            $evaluated += $summary->evaluatedCount();
            $total += $summary->kpiCount();
            $atTarget += $summary->atTargetCount();
        }

        $previousOverall = DashboardProvider::average(array_map(static fn ($s): ?float => $s->previousAverage, $summaries));
        $overall = DashboardProvider::overallAverage($summaries);

        return $this->render('dashboard/index.html.twig', [
            'week' => $week,
            'previousWeek' => $week->modify('-1 week'),
            'nextWeek' => $week->modify('+1 week'),
            'currentWeek' => Week::resolve(null),
            'summaries' => $summaries,
            'overall' => $overall,
            'overallTrend' => null !== $overall && null !== $previousOverall ? round($overall - $previousOverall, 1) : null,
            'evaluated' => $evaluated,
            'total' => $total,
            'atTarget' => $atTarget,
            'chart' => $this->buildChart($chartBuilder, $provider, $user, $week, $summaries),
        ]);
    }

    /** @param list<DomainSummary> $summaries */
    private function buildChart(ChartBuilderInterface $chartBuilder, DashboardProvider $provider, User $user, \DateTimeImmutable $week, array $summaries): ?Chart
    {
        if ([] === $summaries) {
            return null;
        }

        $weeks = Week::lastWeeks(self::CHART_WEEKS, $week);
        $series = $provider->series($user, $weeks);

        $datasets = [];
        foreach ($summaries as $i => $summary) {
            $color = self::PALETTE[$i % \count(self::PALETTE)];
            $datasets[] = [
                'label' => $summary->domain->getName(),
                'data' => array_values($series[$summary->domain->getId()] ?? []),
                'borderColor' => $color,
                'backgroundColor' => $color,
                'tension' => 0.25,
                'spanGaps' => true,
                'pointRadius' => 6,
                'pointHoverRadius' => 8,
                'pointBorderColor' => '#ffffff',
                'pointBorderWidth' => 2,
                'clip' => false,
            ];
        }
        $datasets[] = [
            'label' => 'Objectif (100 %)',
            'data' => array_fill(0, \count($weeks), 100),
            'borderColor' => '#9ca3af',
            'borderDash' => [6, 6],
            'pointRadius' => 0,
            'borderWidth' => 1.5,
        ];

        $chart = $chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => array_map(static fn (\DateTimeImmutable $w): string => $w->format('d/m'), $weeks),
            'datasets' => $datasets,
        ]);
        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => ['offset' => true],
                'y' => ['beginAtZero' => true, 'suggestedMax' => 120],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ]);

        return $chart;
    }
}
