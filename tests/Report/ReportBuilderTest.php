<?php

namespace App\Tests\Report;

use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\User;
use App\Enum\EvaluationStatus;
use App\Report\ReportBuilder;
use App\Report\ReportCriteria;
use App\Report\ReportQuery;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ReportBuilderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReportBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ReportBuilder::class);
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    private function kpi(string $slug): Kpi
    {
        return self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => $slug])->getKpis()->first();
    }

    private function evaluate(Kpi $kpi, string $week, float $score, EvaluationStatus $status = EvaluationStatus::Submitted): void
    {
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable($week)))->setScore($score);
        if (EvaluationStatus::Draft !== $status) {
            $evaluation->submit();
        }
        if (EvaluationStatus::Validated === $status) {
            $evaluation->validate($this->user('manager@example.com'));
        }
        $this->em->persist($evaluation);
        $this->em->flush();
    }

    public function testDefaultPeriodIsTheLastEightWeeks(): void
    {
        $criteria = $this->builder->criteria($this->user('admin@example.com'), new ReportQuery(), new \DateTimeImmutable('2026-09-30'));

        $this->assertSame('2026-09-28', $criteria->to->format('Y-m-d'));
        $this->assertSame('2026-08-10', $criteria->from->format('Y-m-d'));
        $this->assertSame(8, $criteria->weekCount());
        $this->assertNull($criteria->domain);
        $this->assertFalse($criteria->clamped);
    }

    public function testDatesAreSnappedToMondayAndSwappedIfReversed(): void
    {
        $criteria = $this->builder->criteria(
            $this->user('admin@example.com'),
            new ReportQuery(from: '2026-09-30', to: '2026-09-02'), // mercredis, à l'envers
            new \DateTimeImmutable('2026-10-15'),
        );

        $this->assertSame('2026-08-31', $criteria->from->format('Y-m-d'));
        $this->assertSame('2026-09-28', $criteria->to->format('Y-m-d'));
    }

    public function testVeryLongPeriodIsClamped(): void
    {
        $criteria = $this->builder->criteria(
            $this->user('admin@example.com'),
            new ReportQuery(from: '2000-01-01', to: '2026-09-28'),
            new \DateTimeImmutable('2026-09-30'),
        );

        $this->assertTrue($criteria->clamped);
        $this->assertSame(ReportCriteria::MAX_WEEKS, $criteria->weekCount());
    }

    public function testUnreachableDomainIsRefused(): void
    {
        $finance = self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'finance']);

        $this->expectException(AccessDeniedHttpException::class);
        $this->builder->criteria($this->user('manager@example.com'), new ReportQuery(domain: (string) $finance->getId()));
    }

    public function testStatusFilterAndStats(): void
    {
        $reseau = $this->kpi('reseau-arcep'); // objectif 95
        $this->evaluate($reseau, '2026-09-07', 95, EvaluationStatus::Validated);   // 100 %
        $this->evaluate($reseau, '2026-09-14', 47.5, EvaluationStatus::Submitted); //  50 %
        $this->evaluate($reseau, '2026-09-21', 10, EvaluationStatus::Draft);       // brouillon

        $admin = $this->user('admin@example.com');
        $query = ['from' => '2026-09-01', 'to' => '2026-09-28'];

        $reportable = $this->builder->build($admin, $this->builder->criteria($admin, new ReportQuery(...$query)));
        $this->assertCount(2, $reportable->rows);
        $stat = $reportable->stats[0];
        $this->assertSame('reseau-arcep', $stat->domain->getSlug());
        $this->assertSame(2, $stat->count);
        $this->assertSame(1, $stat->validatedCount);
        $this->assertSame(75.0, $stat->average);
        $this->assertSame(50.0, $stat->atTargetRate);
        $this->assertSame(0, $reportable->stats[1]->count, 'domaine sans saisie');
        $this->assertNull($reportable->stats[1]->average);

        $validated = $this->builder->build($admin, $this->builder->criteria($admin, new ReportQuery(...$query, status: 'validated')));
        $this->assertCount(1, $validated->rows);

        $all = $this->builder->build($admin, $this->builder->criteria($admin, new ReportQuery(...$query, status: 'all')));
        $this->assertCount(3, $all->rows);
        $this->assertSame(['2026-09-07', '2026-09-14', '2026-09-21'], array_map(static fn (Evaluation $e) => $e->getWeekStart()->format('Y-m-d'), $all->rows), 'triées par semaine');
    }

    public function testResultsAreScopedToTheUsersDomains(): void
    {
        $this->evaluate($this->kpi('reseau-arcep'), '2026-09-28', 95);
        $this->evaluate($this->kpi('finance'), '2026-09-28', 100);
        $query = new ReportQuery(from: '2026-09-28', to: '2026-09-28');

        $manager = $this->user('manager@example.com'); // Réseau uniquement
        $result = $this->builder->build($manager, $this->builder->criteria($manager, $query));
        $this->assertCount(1, $result->rows);
        $this->assertCount(1, $result->stats);

        $admin = $this->user('admin@example.com');
        $this->assertCount(2, $this->builder->build($admin, $this->builder->criteria($admin, $query))->rows);

        $director = $this->user('director@example.com');
        $this->assertCount(2, $this->builder->build($director, $this->builder->criteria($director, $query))->rows);
    }

    public function testSingleDomainFilter(): void
    {
        $this->evaluate($this->kpi('reseau-arcep'), '2026-09-28', 95);
        $this->evaluate($this->kpi('finance'), '2026-09-28', 100);
        $finance = self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'finance']);

        $admin = $this->user('admin@example.com');
        $result = $this->builder->build($admin, $this->builder->criteria($admin, new ReportQuery(from: '2026-09-28', to: '2026-09-28', domain: (string) $finance->getId())));

        $this->assertCount(1, $result->rows);
        $this->assertSame('finance', $result->rows[0]->getKpi()->getDomain()->getSlug());
        $this->assertCount(1, $result->stats);
    }
}
