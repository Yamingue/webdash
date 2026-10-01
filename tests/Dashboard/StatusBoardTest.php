<?php

namespace App\Tests\Dashboard;

use App\Dashboard\DashboardProvider;
use App\Dashboard\KpiStatus;
use App\Dashboard\StatusBoard;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StatusBoardTest extends KernelTestCase
{
    private const WEEK = '2026-09-28';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function domain(string $slug): \App\Entity\Domain
    {
        return self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => $slug]);
    }

    private function addKpi(string $slug, string $code, float $target, int $position = 5): Kpi
    {
        $kpi = (new Kpi())->setCode($code)->setName($code)->setDefaultTarget($target)->setPosition($position);
        $this->domain($slug)->addKpi($kpi);
        $this->em->persist($kpi);
        $this->em->flush();

        return $kpi;
    }

    private function evaluate(Kpi $kpi, float $score, string $week = self::WEEK, bool $submit = true): void
    {
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable($week)))->setScore($score);
        if ($submit) {
            $evaluation->submit();
        }
        $this->em->persist($evaluation);
        $this->em->flush();
    }

    private function board(string $email = 'admin@example.com'): StatusBoard
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        $summaries = self::getContainer()->get(DashboardProvider::class)->summaries($user, new \DateTimeImmutable(self::WEEK));

        return StatusBoard::fromSummaries($summaries);
    }

    /** @return array<string, string> code du KPI => statut */
    private function statuses(StatusBoard $board): array
    {
        $map = [];
        foreach ($board->rows as $row) {
            $map[$row->line->kpi->getCode()] = $row->status->value;
        }

        return $map;
    }

    /** Réseau : SCAT1 (95), R1 (100) rouge, A1 (100) ambre, V1 (100) vert ; Finance : SCATX (100) non saisi. */
    private function seedAllStatuses(): void
    {
        $this->evaluate($this->domain('reseau-arcep')->getKpis()->first(), 95);       // SCAT1 : 100 % → vert
        $this->evaluate($this->addKpi('reseau-arcep', 'R1', 100), 40);                // 40 % → rouge
        $this->evaluate($this->addKpi('reseau-arcep', 'A1', 100), 90);                // 90 % → ambre
        $this->addKpi('reseau-arcep', 'M1', 100);                                      // jamais saisi → non renseigné
    }

    public function testCountsAddUpToTheNumberOfActiveKpis(): void
    {
        $this->seedAllStatuses();
        $board = $this->board();

        $this->assertSame(1, $board->count(KpiStatus::Red));
        $this->assertSame(1, $board->count(KpiStatus::Amber));
        $this->assertSame(1, $board->count(KpiStatus::Green));
        $this->assertSame(2, $board->count(KpiStatus::Missing), 'M1 (Réseau) et SCATX (Finance)');
        $this->assertSame(5, $board->total());
        $this->assertSame($board->total(), array_sum(array_map(static fn (KpiStatus $s): int => $board->count($s), KpiStatus::cases())));
        $this->assertEquals(['SCAT1' => 'green', 'R1' => 'red', 'A1' => 'amber', 'M1' => 'missing', 'SCATX' => 'missing'], $this->statuses($board));
    }

    public function testRowsAreSortedMostUrgentFirst(): void
    {
        $this->seedAllStatuses();
        $this->evaluate($this->addKpi('reseau-arcep', 'R2', 100), 10); // 10 % : plus urgent que R1 (40 %)

        $codes = array_map(static fn ($r) => $r->line->kpi->getCode(), $this->board()->rows);

        $this->assertSame(['R2', 'R1', 'A1', 'M1', 'SCATX', 'SCAT1'], $codes, 'rouges (taux croissant), ambre, non renseignés, verts');
    }

    public function testBreakdownByDomainOmitsEmptyDomains(): void
    {
        $this->seedAllStatuses();
        $board = $this->board();

        $this->assertSame(['Réseau & ARCEP' => 1], $board->breakdown(KpiStatus::Red));
        $this->assertSame(['Réseau & ARCEP' => 1, 'Finance' => 1], $board->breakdown(KpiStatus::Missing));
        $this->assertSame(['Réseau & ARCEP' => 1], $board->breakdown(KpiStatus::Green));
    }

    public function testDraftsInactiveKpisAndZeroTargetsAreHandledAsDocumented(): void
    {
        $draft = $this->addKpi('reseau-arcep', 'DRAFT', 100);
        $zero = $this->addKpi('reseau-arcep', 'ZERO', 0);
        $off = $this->addKpi('reseau-arcep', 'OFF', 100);
        $this->evaluate($draft, 100, submit: false);   // brouillon : ne compte pas
        $this->evaluate($zero, 5);                      // objectif à 0 : taux non calculable
        $this->evaluate($off, 100);
        $off->setActive(false);
        $this->em->flush();

        $statuses = $this->statuses($this->board());

        $this->assertSame('missing', $statuses['DRAFT']);
        $this->assertSame('missing', $statuses['ZERO']);
        $this->assertArrayNotHasKey('OFF', $statuses, 'un KPI inactif n\'est pas compté');
    }

    public function testLowerIsBetterKpiUsesItsOwnRate(): void
    {
        $churn = $this->addKpi('reseau-arcep', 'CHURN', 10);
        $churn->setLowerIsBetter(true);
        $this->em->flush();
        $this->evaluate($churn, 9); // 10 % sous l'objectif → 110 % → vert

        $this->assertSame('green', $this->statuses($this->board())['CHURN']);

        $other = $this->addKpi('reseau-arcep', 'CHURN2', 10);
        $other->setLowerIsBetter(true);
        $this->em->flush();
        $this->evaluate($other, 15); // 50 % au-dessus → 50 % → rouge
        $this->assertSame('red', $this->statuses($this->board())['CHURN2']);
    }

    public function testMissingReasons(): void
    {
        $scat1 = $this->domain('reseau-arcep')->getKpis()->first();
        $this->evaluate($scat1, 76, '2026-09-21');                       // ancienne valeur : 80 %
        $zero = $this->addKpi('reseau-arcep', 'ZERO', 0);
        $this->evaluate($zero, 5);

        $reasons = [];
        foreach ($this->board()->rows as $row) {
            $reasons[$row->line->kpi->getCode()] = $row->missingReason();
        }

        $this->assertSame('Dernière valeur : 80 % (semaine du 21/09)', $reasons['SCAT1']);
        $this->assertSame('Objectif à 0 : taux non calculable', $reasons['ZERO']);
        $this->assertSame('Aucune évaluation soumise ou validée', $reasons['SCATX'], 'Finance : jamais saisi');
    }

    public function testRenseignedKpiHasNoMissingReason(): void
    {
        $this->evaluate($this->domain('reseau-arcep')->getKpis()->first(), 95);

        $row = $this->board()->rows[array_search('SCAT1', array_map(static fn ($r) => $r->line->kpi->getCode(), $this->board()->rows), true)];

        $this->assertNull($row->missingReason());
        $this->assertSame(100.0, $row->rate());
    }

    public function testBoardIsScopedToTheUsersDomains(): void
    {
        $this->seedAllStatuses();

        $manager = $this->board('manager@example.com'); // responsable de Réseau seulement

        $this->assertSame(4, $manager->total(), 'SCAT1, R1, A1, M1 : pas de KPI de Finance');
        $this->assertSame(['Réseau & ARCEP' => 1], $manager->breakdown(KpiStatus::Missing));
        $this->assertSame(5, $this->board('director@example.com')->total());
    }

    public function testEmptyBoard(): void
    {
        $board = StatusBoard::fromSummaries([]);

        $this->assertSame(0, $board->total());
        $this->assertSame(0, $board->count(KpiStatus::Red));
        $this->assertSame([], $board->breakdown(KpiStatus::Green));
    }
}
