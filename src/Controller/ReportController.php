<?php

namespace App\Controller;

use App\Entity\User;
use App\Report\CsvExporter;
use App\Report\ReportBuilder;
use App\Report\ReportQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Rapports par période, limités aux domaines accessibles à l'utilisateur. */
final class ReportController extends AbstractController
{
    /** Lignes détaillées affichées à l'écran ; l'export CSV, lui, est complet. */
    private const DISPLAY_LIMIT = 500;

    #[Route('/rapports', name: 'app_report_index', methods: ['GET'])]
    public function index(
        ReportBuilder $builder,
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ReportQuery $query = new ReportQuery(),
    ): Response {
        $result = $builder->build($user, $builder->criteria($user, $query));

        return $this->render('report/index.html.twig', [
            'result' => $result,
            'criteria' => $result->criteria,
            'domains' => $builder->visibleDomains($user),
            'displayLimit' => self::DISPLAY_LIMIT,
        ]);
    }

    #[Route('/rapports/export.csv', name: 'app_report_export', methods: ['GET'])]
    public function export(
        ReportBuilder $builder,
        CsvExporter $exporter,
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] ReportQuery $query = new ReportQuery(),
    ): StreamedResponse {
        return $exporter->response($builder->build($user, $builder->criteria($user, $query)));
    }
}
