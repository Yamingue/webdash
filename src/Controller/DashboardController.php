<?php

namespace App\Controller;

use App\Dashboard\DashboardProvider;
use App\Dashboard\KpiStatus;
use App\Dashboard\StatusBoard;
use App\Entity\User;
use App\Service\Week;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        Request $request,
        DashboardProvider $provider,
        #[CurrentUser] User $user,
    ): Response {
        $week = Week::resolve($request->query->getString('week') ?: null);
        $summaries = $provider->summaries($user, $week);

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
            'board' => StatusBoard::fromSummaries($summaries),
            'statuses' => KpiStatus::cases(),
        ]);
    }
}
