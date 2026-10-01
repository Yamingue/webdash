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

    public function testDomainPageShowsItsOwnScoreGaugeWithTrendAndCalculationLink(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->evaluate('reseau-arcep', '2026-09-21', 76);  // 80 %
        $this->evaluate('reseau-arcep', '2026-09-28', 95);  // 100 %

        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-28');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('svg[role="img"][aria-label="Score du domaine : 100 %"]'));
        $this->assertSelectorExists('svg[role="img"] path.stroke-indigo-500', 'arc de progression');
        $this->assertSelectorTextContains('main', '+20 pts', 'tendance par rapport à la semaine précédente');
        $this->assertSelectorExists('main a[href="/d/reseau-arcep/calcul?week=2026-09-28"]');
        $this->assertSelectorTextContains('main', 'Comment le score est calculé');
    }

    public function testDomainPageShowsItsKpiCounters(): void
    {
        $client = $this->clientFor('manager@example.com');
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $reseau = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $second = (new \App\Entity\Kpi())->setCode('SCAT2')->setName('Couverture 4G')->setDefaultTarget(100)->setPosition(1);
        $reseau->addKpi($second);
        $em->persist($second);
        $em->flush();
        $this->evaluate('reseau-arcep', '2026-09-28', 95); // SCAT1 à 100 %, SCAT2 non saisi

        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-28');

        $text = preg_replace('/\s+/', ' ', $crawler->filter('main .grid')->first()->text());
        $this->assertStringContainsString('1 / 2 KPI évalués', $text);
        $this->assertStringContainsString('1 KPI à l\'objectif', $text);
        $this->assertStringContainsString('1 KPI sans saisie', $text);
    }

    public function testGaugeWithoutDataOnTheDomainPage(): void
    {
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', '/d/reseau-arcep?week=2020-01-06');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('svg[role="img"][aria-label="Score du domaine : aucune donnée"]'));
        $this->assertSelectorNotExists('svg[role="img"] path.stroke-indigo-500');
        $this->assertSelectorTextContains('main', 'Aucune donnée comparable');
    }

    public function testTheGaugeShowsTheScoreOfThatDomainOnly(): void
    {
        $client = $this->clientFor('director@example.com');
        $this->evaluate('reseau-arcep', '2026-09-28', 95);   // Réseau 100 %
        $this->evaluate('finance', '2026-09-28', 50);        // Finance 50 %

        $client->request('GET', '/d/reseau-arcep?week=2026-09-28');
        $this->assertSelectorExists('svg[aria-label="Score du domaine : 100 %"]');

        $client->request('GET', '/d/finance?week=2026-09-28');
        $this->assertSelectorExists('svg[aria-label="Score du domaine : 50 %"]');
    }

    /** Remplit n semaines consécutives de SCAT1 (3 août + k semaines), avec un score croissant. */
    private function fillWeeks(int $n): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $this->evaluate('reseau-arcep', (new \DateTimeImmutable('2026-08-03'))->modify("+$i weeks")->format('Y-m-d'), 10 * ($i + 1));
        }
    }

    public function testEvaluationHistoryListsWeeksNewestFirstWithBarAndPercentage(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->evaluate('reseau-arcep', '2026-09-21', 76);  // 80 %
        $this->evaluate('reseau-arcep', '2026-09-28', 23);  // 24 %

        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-28');

        $card = $crawler->filter('#historique');
        $this->assertSelectorTextContains('#historique', 'Historique des évaluations');
        $headers = array_map('trim', $card->filter('li[role="presentation"]')->extract(['_text']));
        $this->assertSame(['Semaine du 28/09/2026', 'Semaine du 21/09/2026'], $headers);

        $rows = $card->filter('li:not([role="presentation"])');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Score SCAT1', $rows->eq(0)->text());
        $this->assertStringContainsString('24%', $rows->eq(0)->text());
        $this->assertStringContainsString('80%', $rows->eq(1)->text());
        $this->assertCount(2, $card->filter('[role="progressbar"]'));
        $this->assertSame('24', $card->filter('[role="progressbar"]')->eq(0)->attr('aria-valuenow'));
        $this->assertSelectorTextContains('#historique', 'Semaines 1 à 2 sur 2');
        $this->assertSelectorNotExists('#historique nav[aria-label="Pagination"]', 'une seule page : pas de pagination');
    }

    public function testEvaluationHistoryIsPaginated(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->fillWeeks(10); // 3 août → 5 octobre

        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-28');
        $headers = array_map('trim', $crawler->filter('#historique li[role="presentation"]')->extract(['_text']));
        $this->assertSame(['Semaine du 05/10/2026', 'Semaine du 28/09/2026', 'Semaine du 21/09/2026', 'Semaine du 14/09/2026'], $headers);
        $this->assertSelectorTextContains('#historique', 'Semaines 1 à 4 sur 10 · page 1 sur 3');

        $nav = $crawler->filter('#historique nav[aria-label="Pagination"]');
        $this->assertCount(1, $nav);
        $this->assertSelectorExists('#historique [aria-current="page"]');
        $this->assertSame('1', trim($nav->filter('[aria-current="page"]')->text()));
        $this->assertCount(0, $nav->filter('a[rel="prev"]'), 'pas de « Précédent » sur la première page');
        $next = $nav->filter('a[rel="next"]');
        $this->assertCount(1, $next);
        $this->assertStringContainsString('page=2', $next->attr('href'));
        $this->assertStringContainsString('week=2026-09-28', $next->attr('href'), 'la semaine choisie est conservée');
        $this->assertStringEndsWith('#historique', $next->attr('href'), 'on reste sur la carte');

        // page 2 : semaines plus anciennes
        $crawler = $client->click($next->link());
        $headers = array_map('trim', $crawler->filter('#historique li[role="presentation"]')->extract(['_text']));
        $this->assertSame(['Semaine du 07/09/2026', 'Semaine du 31/08/2026', 'Semaine du 24/08/2026', 'Semaine du 17/08/2026'], $headers);
        $this->assertSelectorTextContains('#historique', 'Semaines 5 à 8 sur 10 · page 2 sur 3');
        $this->assertCount(1, $crawler->filter('#historique nav a[rel="prev"]'));

        // dernière page : 2 semaines, plus de « Suivant »
        $crawler = $client->request('GET', '/d/reseau-arcep?week=2026-09-28&page=3');
        $this->assertCount(2, $crawler->filter('#historique li[role="presentation"]'));
        $this->assertCount(0, $crawler->filter('#historique nav a[rel="next"]'));
        $this->assertSelectorTextContains('#historique', 'Semaines 9 à 10 sur 10');
    }

    public function testEvaluationHistoryPageParameterIsRobust(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->fillWeeks(6);

        foreach (['0', '-3', 'abc', ''] as $bad) {
            $crawler = $client->request('GET', '/d/reseau-arcep?page='.$bad);
            $this->assertResponseIsSuccessful("page=$bad");
            $this->assertSelectorTextContains('#historique', 'page 1 sur 2');
        }

        $client->request('GET', '/d/reseau-arcep?page[]=2');
        $this->assertResponseIsSuccessful('un paramètre tableau ne provoque pas d\'erreur');
        $this->assertSelectorTextContains('#historique', 'page 1 sur 2');

        $client->request('GET', '/d/reseau-arcep?page=99');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#historique', 'page 2 sur 2', 'au-delà de la dernière page : dernière page');
    }

    public function testEvaluationHistoryEmptyAndHidesDrafts(): void
    {
        $client = $this->clientFor('manager@example.com');
        $client->request('GET', '/d/reseau-arcep');
        $this->assertSelectorTextContains('#historique', 'Aucune évaluation soumise ou validée');
        $this->assertSelectorNotExists('#historique nav[aria-label="Pagination"]');

        // un brouillon n'apparaît pas dans l'historique
        $container = static::getContainer();
        $kpi = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
        $container->get(EntityManagerInterface::class)->persist((new Evaluation($kpi, new \DateTimeImmutable('2026-09-28')))->setScore(5));
        $container->get(EntityManagerInterface::class)->flush();
        $client->request('GET', '/d/reseau-arcep');
        $this->assertSelectorTextContains('#historique', 'Aucune évaluation soumise ou validée');
    }

    public function testEvaluationHistoryFollowsTheDomainAccessRules(): void
    {
        $client = $this->clientFor('manager@example.com'); // responsable de Réseau seulement
        $client->request('GET', '/d/finance?page=2');
        $this->assertResponseStatusCodeSame(403);

        $client = $this->clientFor('multi@example.com');   // lecteur en Finance : peut voir l'historique
        $client->request('GET', '/d/finance');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#historique');
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
