<?php

namespace App\Tests\Report;

use App\Entity\Domain;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\User;
use App\Report\CsvExporter;
use App\Report\ReportCriteria;
use App\Report\ReportResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsvExporterTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function textCases(): iterable
    {
        yield 'normal' => ['Réseau', 'Réseau'];
        yield 'vide' => ['', ''];
        yield 'formule =' => ['=HYPERLINK("http://x")', '\'=HYPERLINK("http://x")'];
        yield 'formule +' => ['+33123', '\'+33123'];
        yield 'formule -' => ['-1+1', '\'-1+1'];
        yield 'formule @' => ['@SUM(A1)', '\'@SUM(A1)'];
        yield 'tabulation' => ["\tcmd", "'\tcmd"];
        yield 'signe au milieu' => ['a=b', 'a=b'];
    }

    #[DataProvider('textCases')]
    public function testTextNeutralisesFormulas(string $input, string $expected): void
    {
        $this->assertSame($expected, CsvExporter::text($input));
    }

    public function testNumbersUseDecimalComma(): void
    {
        $this->assertSame('95,5', CsvExporter::number(95.5));
        $this->assertSame('100', CsvExporter::number(100.0));
        $this->assertSame('33,33', CsvExporter::number(33.333333));
    }

    public function testRowAndStreamedOutput(): void
    {
        $domain = (new Domain())->setName('=Finance');
        $kpi = (new Kpi())->setCode('K1')->setName('Chiffre; "brut"')->setDefaultTarget(200)->setUnit('M');
        $domain->addKpi($kpi);
        $author = (new User())->setFullName('Awa Diallo');
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable('2026-09-30')))
            ->setScore(150)->setComment("ligne 1\nligne 2")->setCreatedBy($author);
        $evaluation->submit();

        $exporter = new CsvExporter();
        $row = $exporter->row($evaluation);
        $this->assertSame('\'=Finance', $row[0]);
        $this->assertSame('2026-09-28', $row[3]);
        $this->assertSame(['200', '150', '75'], [$row[4], $row[5], $row[6]]);
        $this->assertSame('Soumise', $row[10]);
        $this->assertSame(['Plus haut = mieux', '1'], [$row[8], $row[9]], 'sens et poids');
        $this->assertSame('Awa Diallo', $row[12]);
        $this->assertSame('', $row[14], 'pas de valideur');
        $this->assertCount(\count(CsvExporter::HEADERS), $row);

        $criteria = new ReportCriteria(new \DateTimeImmutable('2026-09-07'), new \DateTimeImmutable('2026-09-28'), null, ReportCriteria::STATUS_REPORTABLE);
        $response = $exporter->response(new ReportResult($criteria, [$evaluation], []));
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('rapport-20260907-20260928.csv', $response->headers->get('Content-Disposition'));

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'BOM UTF-8 pour Excel');
        $lines = str_getcsv(substr($csv, 3), "\n", '"', '');
        $this->assertStringStartsWith('Domaine;"Code KPI";KPI;', $lines[0]);
        $this->assertStringContainsString('"Chiffre; ""brut"""', $csv, 'guillemets et séparateurs protégés');
        $this->assertStringContainsString("\"ligne 1\nligne 2\"", $csv, 'retour à la ligne protégé');
    }
}
