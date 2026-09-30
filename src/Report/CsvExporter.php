<?php

namespace App\Report;

use App\Entity\Evaluation;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export CSV pensé pour Excel en français : séparateur « ; », virgule décimale, BOM UTF-8.
 * Les cellules texte commençant par = + - @ sont neutralisées (injection de formules).
 */
final class CsvExporter
{
    public const HEADERS = [
        'Domaine', 'Code KPI', 'KPI', 'Semaine (lundi)', 'Objectif', 'Score', 'Atteinte (%)', 'Unité', 'Sens', 'Poids',
        'Statut', 'Commentaire', 'Saisi par', 'Soumis le', 'Validé par', 'Validé le',
    ];

    public function response(ReportResult $result): StreamedResponse
    {
        $criteria = $result->criteria;
        $filename = \sprintf('rapport-%s-%s.csv', $criteria->from->format('Ymd'), $criteria->to->format('Ymd'));

        $response = new StreamedResponse(function () use ($result): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS, ';', '"', '');
            foreach ($result->rows as $evaluation) {
                fputcsv($out, $this->row($evaluation), ';', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /** @return list<string> */
    public function row(Evaluation $evaluation): array
    {
        $kpi = $evaluation->getKpi();

        return [
            self::text($kpi->getDomain()->getName()),
            self::text($kpi->getCode()),
            self::text($kpi->getName()),
            $evaluation->getWeekStart()->format('Y-m-d'),
            self::number($evaluation->getTarget()),
            null === $evaluation->getScore() ? '' : self::number($evaluation->getScore()),
            null === $evaluation->getAchievement() ? '' : self::number($evaluation->getAchievement()),
            self::text($kpi->getUnit() ?? ''),
            $evaluation->isLowerIsBetter() ? 'Plus bas = mieux' : 'Plus haut = mieux',
            self::number($evaluation->getWeight()),
            $evaluation->getStatus()->label(),
            self::text($evaluation->getComment() ?? ''),
            self::text($evaluation->getCreatedBy()?->getFullName() ?? ''),
            $evaluation->getSubmittedAt()?->format('Y-m-d H:i') ?? '',
            self::text($evaluation->getValidatedBy()?->getFullName() ?? ''),
            $evaluation->getValidatedAt()?->format('Y-m-d H:i') ?? '',
        ];
    }

    /** Neutralise une cellule texte que le tableur interpréterait comme une formule. */
    public static function text(string $value): string
    {
        return '' !== $value && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    public static function number(float $value): string
    {
        return str_replace('.', ',', (string) round($value, 2));
    }
}
