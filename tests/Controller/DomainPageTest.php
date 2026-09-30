<?php

namespace App\Tests\Controller;

use App\Entity\Evaluation;
use App\Repository\DomainRepository;
use App\Service\Week;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class DomainPageTest extends AppWebTestCase
{
    private function evaluate(string $slug, string $week, float $score, ?float $target = null): void
    {
        $container = static::getContainer();
        $kpi = $container->get(DomainRepository::class)->findOneBy(['slug' => $slug])->getKpis()->first();
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable($week)))->setScore($score);
        if (null !== $target) {
            $evaluation->setTarget($target);
        }
        $evaluation->submit();
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($evaluation);
        $em->flush();
    }

    public function testEachKpiGetsAValueVsTargetChart(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->evaluate('reseau-arcep', '2026-09-14', 70, 80);

        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-28');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'Évolution valeur / objectif par KPI');
        $this->assertSelectorTextContains('main', '12 semaines jusqu\'au 28/09/2026');
        $this->assertCount(1, $crawler->filter('canvas'), 'un graphique pour le KPI qui a des valeurs');

        $chartData = html_entity_decode($crawler->filter('canvas')->attr('data-symfony--ux-chartjs--chart-view-value'));
        $this->assertStringContainsString('"label":"Valeur"', $chartData);
        $this->assertStringContainsString('"label":"Objectif"', $chartData);
    }

    public function testKpiWithoutValuesShowsAnEmptyMessageInsteadOfAChart(): void
    {
        $client = $this->clientFor('manager@example.com');
        $client->request('GET', '/d/reseau-arcep');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('canvas');
        $this->assertSelectorTextContains('main', 'Aucune valeur soumise sur cette période');
    }

    public function testWeekSelectorMovesTheWindow(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->evaluate('reseau-arcep', '2026-09-14', 70);

        // la semaine choisie est la dernière des 12 : une valeur postérieure n'apparaît pas
        $client->request('GET', '/d/reseau-arcep?week=2026-09-07');
        $this->assertSelectorNotExists('canvas');

        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-30'); // mercredi → lundi 28
        $this->assertSame('2026-09-28', $crawler->filter('input[name="week"]')->attr('value'));
        $this->assertSelectorExists('canvas');

        $crawler = $client->request('GET', '/d/reseau-arcep');
        $this->assertSame(Week::resolve(null)->format('Y-m-d'), $crawler->filter('input[name="week"]')->attr('value'));
    }

    public function testHistoryTableFollowsTheSelectedWeek(): void
    {
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-28');

        $headers = $crawler->filter('table thead th')->extract(['_text']);
        $this->assertSame('28/09', trim(end($headers)), 'la dernière colonne est la semaine choisie');
        $this->assertContains('24/08', array_map('trim', $headers));
    }

    public function testAccessRulesAreUnchanged(): void
    {
        $client = $this->clientFor('manager@example.com');
        $client->request('GET', '/d/finance');
        $this->assertResponseStatusCodeSame(403);

        $client = $this->clientFor('director@example.com');
        $client->request('GET', '/d/finance');
        $this->assertResponseIsSuccessful();
    }
}
