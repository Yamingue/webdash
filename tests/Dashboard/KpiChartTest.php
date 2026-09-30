<?php

namespace App\Tests\Dashboard;

use App\Dashboard\DashboardProvider;
use App\Dashboard\KpiChartBuilder;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Repository\DomainRepository;
use App\Service\Week;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KpiChartTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DashboardProvider $provider;
    private KpiChartBuilder $charts;
    private Kpi $kpi;
    /** @var list<\DateTimeImmutable> */
    private array $weeks;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->provider = $container->get(DashboardProvider::class);
        $this->charts = $container->get(KpiChartBuilder::class);
        $this->kpi = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first(); // objectif 95, unité %
        $this->weeks = Week::lastWeeks(4, new \DateTimeImmutable('2026-09-30')); // 09-07, 09-14, 09-21, 09-28
    }

    private function evaluate(string $week, float $score, ?float $target = null, bool $submit = true): void
    {
        $evaluation = (new Evaluation($this->kpi, new \DateTimeImmutable($week)))->setScore($score);
        if (null !== $target) {
            $evaluation->setTarget($target);
        }
        if ($submit) {
            $evaluation->submit();
        }
        $this->em->persist($evaluation);
        $this->em->flush();
    }

    public function testSeriesAlignedOnWeeksWithGaps(): void
    {
        $this->evaluate('2026-09-07', 60);
        $this->evaluate('2026-09-21', 90);

        $series = $this->provider->kpiSeries($this->kpi->getDomain(), $this->weeks)[$this->kpi->getId()];

        $this->assertSame([60.0, null, 90.0, null], $series['score']);
        $this->assertSame([95.0, null, 95.0, null], $series['target']);
    }

    public function testTargetSeriesKeepsHistoricalTargets(): void
    {
        $this->evaluate('2026-09-07', 60);            // objectif 95 (valeur par défaut au moment de la saisie)
        $this->evaluate('2026-09-14', 70, 80);        // objectif ajusté à 80 cette semaine-là
        $this->kpi->setDefaultTarget(120);            // nouvelle valeur par défaut : ne touche pas l'historique
        $this->em->flush();
        $this->evaluate('2026-09-21', 100);           // objectif 120

        $series = $this->provider->kpiSeries($this->kpi->getDomain(), $this->weeks)[$this->kpi->getId()];

        $this->assertSame([95.0, 80.0, 120.0, null], $series['target']);
    }

    public function testDraftsAndInactiveKpisAreExcluded(): void
    {
        $this->evaluate('2026-09-07', 60, null, false); // brouillon

        $series = $this->provider->kpiSeries($this->kpi->getDomain(), $this->weeks)[$this->kpi->getId()];
        $this->assertSame([null, null, null, null], $series['score']);

        $this->kpi->setActive(false);
        $this->em->flush();
        $this->assertArrayNotHasKey($this->kpi->getId(), $this->provider->kpiSeries($this->kpi->getDomain(), $this->weeks));
        $this->assertSame([], $this->provider->kpiSeries($this->kpi->getDomain(), []));
    }

    public function testNoChartWithoutAnyValue(): void
    {
        $series = $this->provider->kpiSeries($this->kpi->getDomain(), $this->weeks)[$this->kpi->getId()];

        $this->assertNull($this->charts->build($this->kpi, $this->weeks, $series));
    }

    public function testChartHasValueAndTargetDatasets(): void
    {
        $this->evaluate('2026-09-14', 70, 80);
        $series = $this->provider->kpiSeries($this->kpi->getDomain(), $this->weeks)[$this->kpi->getId()];

        $chart = $this->charts->build($this->kpi, $this->weeks, $series);

        $data = $chart->getData();
        $this->assertSame(['07/09', '14/09', '21/09', '28/09'], $data['labels']);
        $this->assertSame(['Valeur', 'Objectif'], array_column($data['datasets'], 'label'));
        $this->assertSame([null, 70.0, null, null], $data['datasets'][0]['data']);
        $this->assertSame([null, 80.0, null, null], $data['datasets'][1]['data']);
        $this->assertSame([6, 6], $data['datasets'][1]['borderDash'], 'objectif en pointillés');
        $this->assertTrue($chart->getOptions()['scales']['y']['title']['display']);
        $this->assertSame('%', $chart->getOptions()['scales']['y']['title']['text']);
    }
}
