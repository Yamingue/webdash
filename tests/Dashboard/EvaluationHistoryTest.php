<?php

namespace App\Tests\Dashboard;

use App\Dashboard\EvaluationHistory;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Repository\DomainRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EvaluationHistoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EvaluationHistory $history;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->history = self::getContainer()->get(EvaluationHistory::class);
    }

    private function domain(string $slug = 'reseau-arcep'): \App\Entity\Domain
    {
        return self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => $slug]);
    }

    private function evaluate(Kpi $kpi, string $week, float $score = 50, bool $submit = true): void
    {
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable($week)))->setScore($score);
        if ($submit) {
            $evaluation->submit();
        }
        $this->em->persist($evaluation);
        $this->em->flush();
    }

    /** Remplit n semaines consécutives (lundi 2026-08-03 + k semaines) pour le KPI donné. */
    private function fillWeeks(Kpi $kpi, int $n): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $this->evaluate($kpi, (new \DateTimeImmutable('2026-08-03'))->modify("+$i weeks")->format('Y-m-d'));
        }
    }

    /** @return list<string> */
    private function weeksOf(\App\Dashboard\HistoryPage $page): array
    {
        return array_map(static fn ($g) => $g->week->format('Y-m-d'), $page->groups);
    }

    public function testEmptyHistory(): void
    {
        $page = $this->history->page($this->domain());

        $this->assertSame([], $page->groups);
        $this->assertSame(1, $page->page);
        $this->assertSame(1, $page->pages);
        $this->assertSame(0, $page->totalWeeks);
        $this->assertSame(0, $page->firstWeekNumber());
        $this->assertSame(0, $page->lastWeekNumber());
        $this->assertFalse($page->hasPrevious());
        $this->assertFalse($page->hasNext());
    }

    public function testPagesWalkFromTheNewestWeeksToTheOldest(): void
    {
        $this->fillWeeks($this->domain()->getKpis()->first(), 10); // 3 août → 5 octobre

        $first = $this->history->page($this->domain(), 1);
        $this->assertSame(10, $first->totalWeeks);
        $this->assertSame(3, $first->pages, '10 semaines à 4 par page');
        $this->assertSame(['2026-10-05', '2026-09-28', '2026-09-21', '2026-09-14'], $this->weeksOf($first), 'la plus récente d\'abord');
        $this->assertSame([1, 4], [$first->firstWeekNumber(), $first->lastWeekNumber()]);
        $this->assertFalse($first->hasPrevious());
        $this->assertTrue($first->hasNext());

        $second = $this->history->page($this->domain(), 2);
        $this->assertSame(['2026-09-07', '2026-08-31', '2026-08-24', '2026-08-17'], $this->weeksOf($second));
        $this->assertTrue($second->hasPrevious() && $second->hasNext());

        $last = $this->history->page($this->domain(), 3);
        $this->assertSame(['2026-08-10', '2026-08-03'], $this->weeksOf($last), 'dernière page incomplète');
        $this->assertSame([9, 10], [$last->firstWeekNumber(), $last->lastWeekNumber()]);
        $this->assertTrue($last->hasPrevious());
        $this->assertFalse($last->hasNext());
    }

    public function testOutOfRangePagesAreClamped(): void
    {
        $this->fillWeeks($this->domain()->getKpis()->first(), 6);

        $this->assertSame(1, $this->history->page($this->domain(), 0)->page);
        $this->assertSame(1, $this->history->page($this->domain(), -4)->page);
        $this->assertSame(2, $this->history->page($this->domain(), 99)->page, 'au-delà : dernière page');
        $this->assertCount(2, $this->history->page($this->domain(), 99)->groups);
    }

    public function testExactMultipleOfThePageSizeHasNoEmptyLastPage(): void
    {
        $this->fillWeeks($this->domain()->getKpis()->first(), 8);

        $this->assertSame(2, $this->history->page($this->domain())->pages);
        $this->assertCount(4, $this->history->page($this->domain(), 2)->groups);
    }

    public function testCustomPageSize(): void
    {
        $this->fillWeeks($this->domain()->getKpis()->first(), 5);

        $page = $this->history->page($this->domain(), 2, 2);
        $this->assertSame(3, $page->pages);
        $this->assertCount(2, $page->groups);
        $this->assertSame(0, \count($this->history->page($this->domain(), 1, 0)->groups) - 1, 'une taille de page invalide est ramenée à 1');
    }

    public function testWeekGroupsHoldEveryKpiInDisplayOrder(): void
    {
        $scat1 = $this->domain()->getKpis()->first();
        $second = (new Kpi())->setCode('SCAT2')->setName('Couverture 4G')->setDefaultTarget(100)->setPosition(1);
        $this->domain()->addKpi($second);
        $this->em->persist($second);
        $this->em->flush();

        $this->evaluate($second, '2026-09-28');   // saisi en premier : l'ordre vient de la position du KPI, pas de la saisie
        $this->evaluate($scat1, '2026-09-28');
        $this->evaluate($scat1, '2026-09-21');

        $page = $this->history->page($this->domain());
        $this->assertSame(['2026-09-28', '2026-09-21'], $this->weeksOf($page));
        $this->assertSame(['SCAT1', 'SCAT2'], array_map(static fn ($l) => $l->kpi->getCode(), $page->groups[0]->lines));
        $this->assertSame(['SCAT1'], array_map(static fn ($l) => $l->kpi->getCode(), $page->groups[1]->lines), 'seules les évaluations existantes sont listées');
        $this->assertNotNull($page->groups[0]->lines[0]->evaluation);
        $this->assertSame(2, $page->totalWeeks, 'on pagine par semaine, pas par évaluation');
    }

    public function testDraftsAreNotListedAndOtherDomainsAreIgnored(): void
    {
        $scat1 = $this->domain()->getKpis()->first();
        $this->evaluate($scat1, '2026-09-21', 10, submit: false);   // brouillon
        $this->evaluate($scat1, '2026-09-28');
        $this->evaluate($this->domain('finance')->getKpis()->first(), '2026-09-14'); // autre domaine

        $page = $this->history->page($this->domain());

        $this->assertSame(['2026-09-28'], $this->weeksOf($page));
        $this->assertSame(1, $page->totalWeeks);
        $this->assertSame(['2026-09-14'], $this->weeksOf($this->history->page($this->domain('finance'))));
    }

    public function testHistoryKeepsEvaluationsOfDeactivatedKpis(): void
    {
        $scat1 = $this->domain()->getKpis()->first();
        $this->evaluate($scat1, '2026-09-28');
        $scat1->setActive(false);
        $this->em->flush();

        $page = $this->history->page($this->domain());

        $this->assertSame(['2026-09-28'], $this->weeksOf($page), 'l\'historique reste consultable après désactivation du KPI');
    }
}
