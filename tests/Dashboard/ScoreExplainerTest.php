<?php

namespace App\Tests\Dashboard;

use App\Dashboard\DashboardProvider;
use App\Dashboard\ScoreExplainer;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScoreExplainerTest extends KernelTestCase
{
    private const WEEK = '2026-09-28';

    private EntityManagerInterface $em;
    private ScoreExplainer $explainer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->explainer = self::getContainer()->get(ScoreExplainer::class);
    }

    private function week(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::WEEK);
    }

    private function reseau(): \App\Entity\Domain
    {
        return self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
    }

    private function addKpi(string $code, float $target, float $weight, bool $lowerIsBetter = false): Kpi
    {
        $kpi = (new Kpi())->setCode($code)->setName($code)->setDefaultTarget($target)->setWeight($weight)->setLowerIsBetter($lowerIsBetter)->setPosition(9);
        $this->reseau()->addKpi($kpi);
        $this->em->persist($kpi);
        $this->em->flush();

        return $kpi;
    }

    private function evaluate(Kpi $kpi, float $score, bool $submit = true): Evaluation
    {
        $evaluation = (new Evaluation($kpi, $this->week()))->setScore($score);
        if ($submit) {
            $evaluation->submit();
        }
        $this->em->persist($evaluation);
        $this->em->flush();

        return $evaluation;
    }

    public function testBreakdownAddsUpToTheScore(): void
    {
        $scat1 = $this->reseau()->getKpis()->first(); // objectif 95
        $scat1->setWeight(3);
        $this->em->flush();
        $b = $this->addKpi('B', 100, 1);
        $churn = $this->addKpi('C', 10, 1, true);
        $big = $this->addKpi('D', 100, 1);

        $this->evaluate($scat1, 95);   // 100 %, poids 3
        $this->evaluate($b, 60);       //  60 %
        $this->evaluate($churn, 11);   //  90 % (10 % au-dessus de l'objectif, plus bas = mieux)
        $this->evaluate($big, 190);    // 190 % → plafonné à 120

        $explanation = $this->explainer->forDomain($this->reseau(), $this->week());

        $this->assertCount(4, $explanation->rows);
        $this->assertSame([], $explanation->excluded);
        $this->assertSame([100.0, 60.0, 90.0, 190.0], array_map(static fn ($r) => $r->rate, $explanation->rows));
        $this->assertSame([100.0, 60.0, 90.0, 120.0], array_map(static fn ($r) => $r->counted, $explanation->rows));
        $this->assertSame([false, false, false, true], array_map(static fn ($r) => $r->isCapped(), $explanation->rows));
        $this->assertSame(6.0, $explanation->totalWeight());
        $this->assertSame(570.0, $explanation->weightedSum()); // 100×3 + 60 + 90 + 120
        $this->assertSame(95.0, $explanation->score);
        $this->assertEqualsWithDelta($explanation->score, $explanation->weightedSum() / $explanation->totalWeight(), 0.05, 'le détail redonne bien le score');
    }

    public function testExplanationMatchesTheDashboardScore(): void
    {
        $scat1 = $this->reseau()->getKpis()->first();
        $b = $this->addKpi('B', 100, 2);
        $this->evaluate($scat1, 76);  // 80 %
        $this->evaluate($b, 130);     // 130 % → plafonné

        $manager = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'manager@example.com']);
        $summary = self::getContainer()->get(DashboardProvider::class)->summaries($manager, $this->week())[0];

        $this->assertSame($summary->average, $this->explainer->forDomain($this->reseau(), $this->week())->score);
    }

    public function testExcludedKpisComeWithTheirReason(): void
    {
        $scat1 = $this->reseau()->getKpis()->first();
        $draft = $this->addKpi('DRAFT', 100, 1);
        $missing = $this->addKpi('MISSING', 100, 1);
        $zeroTarget = $this->addKpi('ZERO', 0, 1);
        $inactive = $this->addKpi('OFF', 100, 1);
        $this->evaluate($scat1, 95);
        $this->evaluate($draft, 50, submit: false);
        $this->evaluate($zeroTarget, 5);
        $inactive->setActive(false);
        $this->em->flush();

        $explanation = $this->explainer->forDomain($this->reseau(), $this->week());

        $this->assertCount(1, $explanation->rows, 'seul SCAT1 est pris en compte');
        $reasons = [];
        foreach ($explanation->excluded as $item) {
            $reasons[$item->kpi->getCode()] = $item->reason;
        }
        $this->assertSame(['DRAFT', 'MISSING', 'ZERO'], array_keys($reasons), 'le KPI inactif n\'est pas listé');
        $this->assertStringContainsString('brouillon', $reasons['DRAFT']);
        $this->assertStringContainsString('Aucune évaluation', $reasons['MISSING']);
        $this->assertStringContainsString('Objectif à 0', $reasons['ZERO']);
        $this->assertSame(100.0, $explanation->score);
    }

    public function testNoScoreWhenNothingIsSubmitted(): void
    {
        $explanation = $this->explainer->forDomain($this->reseau(), $this->week());

        $this->assertNull($explanation->score);
        $this->assertSame([], $explanation->rows);
        $this->assertCount(1, $explanation->excluded);
        $this->assertSame(0.0, $explanation->weightedSum());
    }

    public function testGlobalExplanationSplitsDomainsWithAndWithoutData(): void
    {
        $this->evaluate($this->reseau()->getKpis()->first(), 95); // Réseau 100 %, Finance sans donnée

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'admin@example.com']);
        $global = $this->explainer->forUser($admin, $this->week());

        $this->assertSame(['reseau-arcep'], array_map(static fn ($s) => $s->domain->getSlug(), $global->counted));
        $this->assertSame(['finance'], array_map(static fn ($s) => $s->domain->getSlug(), $global->withoutData));
        $this->assertSame(100.0, $global->score);
    }
}
