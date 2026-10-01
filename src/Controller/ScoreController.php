<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\ScoreExplainer;
use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\User;
use App\Security\DomainVoter;
use App\Service\Week;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Pages qui expliquent comment les scores sont calculés (règles, détail par domaine, détail global). */
final class ScoreController extends AbstractController
{
    #[Route('/calcul-des-scores', name: 'app_score_help', methods: ['GET'])]
    public function help(): Response
    {
        return $this->render('score/help.html.twig', [
            'cap' => DashboardProvider::RATE_CAP,
            'warn' => DashboardProvider::THRESHOLD_WARN,
            'goal' => DashboardProvider::THRESHOLD_GOAL,
            'example' => $this->example(),
        ]);
    }

    #[Route('/calcul', name: 'app_score_global', methods: ['GET'])]
    public function global(Request $request, ScoreExplainer $explainer, #[CurrentUser] User $user): Response
    {
        $week = Week::resolve($request->query->getString('week') ?: null);

        return $this->render('score/global.html.twig', ['explanation' => $explainer->forUser($user, $week)] + $this->weekNav($week));
    }

    #[Route('/d/{slug}/calcul', name: 'app_score_domain', methods: ['GET'])]
    #[IsGranted(DomainVoter::VIEW, subject: 'domain')]
    public function domain(#[MapEntity(mapping: ['slug' => 'slug'])] Domain $domain, Request $request, ScoreExplainer $explainer): Response
    {
        if (!$domain->isActive() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }

        $week = Week::resolve($request->query->getString('week') ?: null);

        return $this->render('score/domain.html.twig', [
            'domain' => $domain,
            'explanation' => $explainer->forDomain($domain, $week),
            'cap' => DashboardProvider::RATE_CAP,
        ] + $this->weekNav($week));
    }

    /** @return array<string, \DateTimeImmutable> variables attendues par partials/_week_nav.html.twig */
    private function weekNav(\DateTimeImmutable $week): array
    {
        return [
            'week' => $week,
            'previousWeek' => $week->modify('-1 week'),
            'nextWeek' => $week->modify('+1 week'),
            'currentWeek' => Week::resolve(null),
        ];
    }

    /**
     * Exemple chiffré de la page d'aide, calculé avec le vrai code (et non écrit à la main) :
     * la page ne peut donc pas mentir si une règle change.
     *
     * @return array{rows: list<array{name: string, lowerIsBetter: bool, target: float, score: float, weight: float, rate: float, counted: float}>, score: ?float, sumWeights: float, weightedSum: float}
     */
    private function example(): array
    {
        $domain = new Domain();
        $week = new \DateTimeImmutable('monday this week');
        $definitions = [
            ['Disponibilité réseau', false, 95, 95, 3],
            ['Couverture 4G', false, 100, 60, 1],
            ['Taux de churn', true, 10, 11, 1],
            ['Utilisateurs data', false, 100, 190, 1],
        ];

        $rows = $evaluations = [];
        foreach ($definitions as [$name, $lower, $target, $score, $weight]) {
            $kpi = (new Kpi())->setName($name)->setDefaultTarget($target)->setLowerIsBetter($lower)->setWeight($weight);
            $domain->addKpi($kpi);
            $evaluation = (new Evaluation($kpi, $week))->setScore($score);
            $evaluations[] = $evaluation;
            $rate = (float) $evaluation->getAchievement();
            $rows[] = [
                'name' => $name, 'lowerIsBetter' => $lower, 'target' => (float) $target, 'score' => (float) $score,
                'weight' => (float) $weight, 'rate' => $rate, 'counted' => min($rate, DashboardProvider::RATE_CAP),
            ];
        }

        return [
            'rows' => $rows,
            'score' => DashboardProvider::weightedAverage($evaluations),
            'sumWeights' => array_sum(array_column($rows, 'weight')),
            'weightedSum' => array_sum(array_map(static fn (array $r): float => $r['weight'] * $r['counted'], $rows)),
        ];
    }
}
