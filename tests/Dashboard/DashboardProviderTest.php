<?php

namespace App\Tests\Dashboard;

use App\Dashboard\DashboardProvider;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DashboardProviderTest extends KernelTestCase
{
    private const WEEK = '2026-09-28';
    private const PREVIOUS = '2026-09-21';

    private EntityManagerInterface $em;
    private DashboardProvider $provider;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->provider = self::getContainer()->get(DashboardProvider::class);
    }

    private function monday(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private function reseauKpi(): Kpi
    {
        return self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
    }

    /** @return Evaluation évaluation du KPI pour la semaine, au statut demandé */
    private function evaluate(Kpi $kpi, string $week, float $score, string $status = 'submitted'): Evaluation
    {
        $evaluation = (new Evaluation($kpi, $this->monday($week)))->setScore($score);
        if ('draft' !== $status) {
            $evaluation->submit();
        }
        $this->em->persist($evaluation);
        $this->em->flush();

        return $evaluation;
    }

    private function user(string $email): \App\Entity\User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    public function testAverageAchievementAndTrend(): void
    {
        $kpi = $this->reseauKpi(); // objectif 95
        $this->evaluate($kpi, self::WEEK, 95);       // 100 %
        $this->evaluate($kpi, self::PREVIOUS, 76);   // 80 %

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];

        $this->assertSame(100.0, $summary->average);
        $this->assertSame(80.0, $summary->previousAverage);
        $this->assertSame(20.0, $summary->trend());
        $this->assertSame(1, $summary->evaluatedCount());
        $this->assertSame(1, $summary->atTargetCount());
        $this->assertSame(0, $summary->missingCount());
    }

    public function testDomainAverageIsTheMeanOfItsKpis(): void
    {
        $first = $this->reseauKpi();
        $domain = $first->getDomain();
        $second = (new Kpi())->setCode('SCAT2')->setName('Second')->setDefaultTarget(100)->setPosition(1);
        $domain->addKpi($second);
        $this->em->persist($second);
        $this->em->flush();

        $this->evaluate($first, self::WEEK, 95);   // 100 %
        $this->evaluate($second, self::WEEK, 50);  //  50 %

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];
        $this->assertSame(75.0, $summary->average);
        $this->assertSame(2, $summary->kpiCount());
        $this->assertSame(1, $summary->atTargetCount());
    }

    public function testDraftsAreIgnoredAndInactiveKpisExcluded(): void
    {
        $kpi = $this->reseauKpi();
        $this->evaluate($kpi, self::WEEK, 95, 'draft');

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];
        $this->assertNull($summary->average);
        $this->assertSame(1, $summary->missingCount());
        $this->assertNull($summary->trend());

        $kpi->setActive(false);
        $this->em->flush();
        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];
        $this->assertSame(0, $summary->kpiCount());
    }

    public function testVisibilityAndPendingDependOnTheUser(): void
    {
        $week = $this->monday(self::WEEK);
        $this->evaluate($this->reseauKpi(), self::WEEK, 95);

        $this->assertCount(2, $this->provider->summaries($this->user('admin@example.com'), $week));
        $this->assertCount(2, $this->provider->summaries($this->user('director@example.com'), $week));
        $this->assertCount(2, $this->provider->summaries($this->user('multi@example.com'), $week));

        $manager = $this->provider->summaries($this->user('manager@example.com'), $week);
        $this->assertCount(1, $manager, 'le responsable ne voit que son domaine');
        $this->assertSame(1, $manager[0]->pending);

        $multi = $this->provider->summaries($this->user('multi@example.com'), $week);
        foreach ($multi as $summary) {
            $this->assertNull($summary->pending, 'pas de file de validation pour un évaluateur/lecteur');
        }

        $director = $this->provider->summaries($this->user('director@example.com'), $week);
        foreach ($director as $summary) {
            $this->assertNull($summary->pending);
        }
    }

    public function testSummaryForReturnsOnlyThatDomainWithTheSameFiguresAsTheFullList(): void
    {
        $kpi = $this->reseauKpi();
        $this->evaluate($kpi, self::PREVIOUS, 76);  // 80 %
        $this->evaluate($kpi, self::WEEK, 95);      // 100 %

        $manager = $this->user('manager@example.com');
        $week = $this->monday(self::WEEK);

        $single = $this->provider->summaryFor($manager, $kpi->getDomain(), $week);
        $fromList = $this->provider->summaries($manager, $week)[0];

        $this->assertSame($kpi->getDomain(), $single->domain);
        $this->assertSame(100.0, $single->average);
        $this->assertSame(20.0, $single->trend());
        $this->assertSame($fromList->average, $single->average);
        $this->assertSame($fromList->previousAverage, $single->previousAverage);
        $this->assertSame($fromList->evaluatedCount(), $single->evaluatedCount());
        $this->assertSame($fromList->pending, $single->pending);
    }

    public function testSummaryForDoesNotMixOtherDomains(): void
    {
        $this->evaluate($this->reseauKpi(), self::WEEK, 95);
        $finance = self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'finance']);

        $summary = $this->provider->summaryFor($this->user('admin@example.com'), $finance, $this->monday(self::WEEK));

        $this->assertSame($finance, $summary->domain);
        $this->assertNull($summary->average, 'Finance n\'a aucune évaluation');
        $this->assertSame(1, $summary->kpiCount());
    }

    public function testLatestIsEachKpisLastEntryEvenIfOld(): void
    {
        $kpi = $this->reseauKpi(); // SCAT1, objectif 95
        $this->evaluate($kpi, '2026-08-17', 47.5);   // 50 %
        $this->evaluate($kpi, '2026-09-07', 76);     // 80 % : la plus récente avant la semaine choisie
        $this->evaluate($kpi, '2026-10-05', 95);     // semaine postérieure à celle choisie : ignorée

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];

        $this->assertCount(1, $summary->latest);
        $this->assertSame('2026-09-07', $summary->latest[0]->evaluation->getWeekStart()->format('Y-m-d'));
        $this->assertSame(80.0, $summary->latest[0]->achievement());
        $this->assertNull($summary->average, 'la semaine choisie elle-même n\'a pas de saisie');
    }

    public function testKpisNeverEnteredAreNotInLatestButAreInHistoryWeeks(): void
    {
        $first = $this->reseauKpi();
        $second = (new Kpi())->setCode('SCAT2')->setName('Second')->setDefaultTarget(100)->setPosition(1);
        $first->getDomain()->addKpi($second);
        $this->em->persist($second);
        $this->em->flush();
        $this->evaluate($first, self::WEEK, 95);

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];

        $this->assertCount(1, $summary->latest, 'SCAT2 n\'a jamais été saisi');
        $this->assertCount(1, $summary->history);
        $lines = $summary->history[0]->lines;
        $this->assertCount(2, $lines, 'tous les KPI actifs de la semaine renseignée');
        $this->assertNotNull($lines[0]->evaluation);
        $this->assertNull($lines[1]->evaluation, 'non saisi cette semaine : N/D');
        $this->assertSame(2, $summary->historyRowCount());
    }

    public function testHistoryKeepsOnlyTheLatestFilledWeeksMostRecentFirst(): void
    {
        $kpi = $this->reseauKpi();
        foreach (['2026-08-10', '2026-08-17', '2026-08-31', '2026-09-07', '2026-09-14', '2026-09-28'] as $week) { // 6 semaines, 09-21 vide
            $this->evaluate($kpi, $week, 50);
        }

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];

        $this->assertCount(DashboardProvider::HISTORY_WEEKS, $summary->history);
        $this->assertSame(
            ['2026-09-28', '2026-09-14', '2026-09-07', '2026-08-31'],
            array_map(static fn ($w) => $w->week->format('Y-m-d'), $summary->history),
            'les semaines sans saisie (21/09) ne figurent pas dans l\'historique',
        );
    }

    public function testEntriesOlderThanTheLookbackAreIgnored(): void
    {
        $kpi = $this->reseauKpi();
        $this->evaluate($kpi, '2026-03-02', 50); // plus de 26 semaines avant le 28/09

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];

        $this->assertSame([], $summary->latest);
        $this->assertSame([], $summary->history);
    }

    public function testDraftsAndInactiveKpisNeverAppearInLatestOrHistory(): void
    {
        $kpi = $this->reseauKpi();
        $this->evaluate($kpi, self::PREVIOUS, 50, 'draft');
        $this->evaluate($kpi, self::WEEK, 95);
        $kpi->setActive(false);
        $this->em->flush();

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];
        $this->assertSame([], $summary->latest);
        $this->assertSame([], $summary->history);
    }

    private function addKpi(string $code, float $target, float $weight): Kpi
    {
        $kpi = (new Kpi())->setCode($code)->setName($code)->setDefaultTarget($target)->setWeight($weight)->setPosition(9);
        $this->reseauKpi()->getDomain()->addKpi($kpi);
        $this->em->persist($kpi);
        $this->em->flush();

        return $kpi;
    }

    public function testDomainAverageIsWeighted(): void
    {
        $first = $this->reseauKpi();             // objectif 95, poids 1 (par défaut)
        $first->setWeight(3);
        $this->em->flush();
        $second = $this->addKpi('W2', 100, 1);

        $this->evaluate($first, self::WEEK, 95);  // 100 %, poids 3
        $this->evaluate($second, self::WEEK, 60); //  60 %, poids 1

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];

        // (100×3 + 60×1) ÷ 4 = 90, et non la moyenne simple (80)
        $this->assertSame(90.0, $summary->average);
    }

    public function testEachRateIsCappedInTheAverageButNotInTheKpiLine(): void
    {
        $first = $this->reseauKpi();
        $second = $this->addKpi('W3', 100, 1);
        $this->evaluate($first, self::WEEK, 190); // 200 %
        $this->evaluate($second, self::WEEK, 40); //  40 %

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];

        // plafonné à 120 % : (120 + 40) ÷ 2 = 80, et non (200 + 40) ÷ 2 = 120
        $this->assertSame(80.0, $summary->average);
        $this->assertSame(200.0, $summary->lines[0]->achievement(), 'la ligne du KPI garde son vrai taux');
    }

    public function testChangingAKpiWeightLaterDoesNotRewritePastAverages(): void
    {
        $first = $this->reseauKpi();
        $second = $this->addKpi('W4', 100, 1);
        $this->evaluate($first, self::WEEK, 95);  // 100 %, poids 1
        $this->evaluate($second, self::WEEK, 60); //  60 %, poids 1

        $first->setWeight(9); // nouveau poids : ne concerne que les prochaines évaluations
        $this->em->flush();

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];
        $this->assertSame(80.0, $summary->average);
    }

    public function testLowerIsBetterKpiFeedsTheAverageWithItsOwnRate(): void
    {
        $first = $this->reseauKpi();
        $churn = $this->addKpi('W5', 10, 1);
        $churn->setLowerIsBetter(true);
        $this->em->flush();
        $this->evaluate($first, self::WEEK, 95);  // 100 %
        $this->evaluate($churn, self::WEEK, 11);  // 10 % au-dessus de l'objectif (10) → 90 %

        $summary = $this->provider->summaries($this->user('manager@example.com'), $this->monday(self::WEEK))[0];
        $this->assertSame(95.0, $summary->average); // (100 + 90) ÷ 2
    }

    public function testWeightedAverageHelper(): void
    {
        $this->assertNull(DashboardProvider::weightedAverage([]));

        $kpi = (new Kpi())->setDefaultTarget(100)->setWeight(2);
        (new \App\Entity\Domain())->addKpi($kpi);
        $scored = (new Evaluation($kpi, new \DateTimeImmutable('2026-09-28')))->setScore(50);
        $unscored = new Evaluation($kpi, new \DateTimeImmutable('2026-09-21'));

        $this->assertSame(50.0, DashboardProvider::weightedAverage([$scored, $unscored]), 'les évaluations sans taux sont ignorées');
        $this->assertNull(DashboardProvider::weightedAverage([$unscored]));
    }

    public function testAverageHelperSkipsNulls(): void
    {
        $this->assertNull(DashboardProvider::average([]));
        $this->assertNull(DashboardProvider::average([null, null]));
        $this->assertSame(75.0, DashboardProvider::average([100.0, null, 50.0]));
    }
}
