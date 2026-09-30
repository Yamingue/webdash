<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\KpiChartBuilder;
use App\Entity\Domain;
use App\Repository\EvaluationRepository;
use App\Security\DomainVoter;
use App\Service\Week;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DomainController extends AbstractController
{
    /** Semaines du tableau d'historique. */
    private const HISTORY_WEEKS = 6;
    /** Semaines affichées sur les courbes valeur / objectif. */
    private const CHART_WEEKS = 12;

    #[Route('/d/{slug}', name: 'app_domain_show', methods: ['GET'])]
    #[IsGranted(DomainVoter::VIEW, subject: 'domain')]
    public function show(
        #[MapEntity(mapping: ['slug' => 'slug'])] Domain $domain,
        Request $request,
        EvaluationRepository $evaluations,
        DashboardProvider $provider,
        KpiChartBuilder $chartBuilder,
    ): Response {
        if (!$domain->isActive() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }

        // La semaine choisie est la dernière de l'historique et des courbes.
        $week = Week::resolve($request->query->getString('week') ?: null);

        $weeks = Week::lastWeeks(self::HISTORY_WEEKS, $week);
        $grid = [];
        foreach ($evaluations->findForDomainBetween($domain, $weeks[0], $week) as $evaluation) {
            $grid[$evaluation->getKpi()->getId()][$evaluation->getWeekStart()->format('Y-m-d')] = $evaluation;
        }

        $chartWeeks = Week::lastWeeks(self::CHART_WEEKS, $week);
        $series = $provider->kpiSeries($domain, $chartWeeks);
        $charts = [];
        foreach ($domain->getKpis() as $kpi) {
            if ($kpi->isActive()) {
                $charts[$kpi->getId()] = $chartBuilder->build($kpi, $chartWeeks, $series[$kpi->getId()]);
            }
        }

        return $this->render('domain/show.html.twig', [
            'domain' => $domain,
            'week' => $week,
            'previousWeek' => $week->modify('-1 week'),
            'nextWeek' => $week->modify('+1 week'),
            'currentWeek' => Week::resolve(null),
            'weeks' => $weeks,
            'grid' => $grid,
            'charts' => $charts,
            'chartWeekCount' => self::CHART_WEEKS,
            'pending' => $this->isGranted(DomainVoter::MANAGE, $domain) ? $evaluations->countSubmittedForDomain($domain) : null,
        ]);
    }
}
