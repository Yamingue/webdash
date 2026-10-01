<?php

namespace App\Tests\Controller;

use App\Dashboard\DashboardProvider;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Repository\DomainRepository;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ScorePagesTest extends AppWebTestCase
{
    private const WEEK = '2026-09-28';

    private function seed(): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $reseau = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $scat1 = $reseau->getKpis()->first();
        $scat1->setWeight(3);
        $second = (new Kpi())->setCode('SCAT2')->setName('Couverture 4G')->setDefaultTarget(100)->setWeight(1)->setPosition(1);
        $reseau->addKpi($second);
        $em->persist($second);
        $em->flush();

        foreach ([[$scat1, 95], [$second, 60]] as [$kpi, $score]) {
            $evaluation = (new Evaluation($kpi, new \DateTimeImmutable(self::WEEK)))->setScore($score);
            $evaluation->submit();
            $em->persist($evaluation);
        }
        $em->flush();
    }

    public function testEveryPageNeedsALogin(): void
    {
        $client = static::createClient();
        foreach (['/calcul-des-scores', '/calcul', '/d/reseau-arcep/calcul'] as $url) {
            $client->request('GET', $url);
            $this->assertResponseRedirects('/login', null, $url);
        }
    }

    public function testHelpPageIsOpenToEveryoneAndStatesTheRealConstants(): void
    {
        foreach (['multi@example.com', 'manager@example.com', 'director@example.com', 'admin@example.com'] as $email) {
            $client = $this->clientFor($email);
            $client->request('GET', '/calcul-des-scores');
            $this->assertResponseIsSuccessful($email);
        }

        $client = $this->clientFor('multi@example.com');
        $client->request('GET', '/calcul-des-scores');
        $text = $client->getCrawler()->filter('main')->text();
        $this->assertStringContainsString('plafonné à '.(int) DashboardProvider::RATE_CAP.' %', $text);
        $this->assertStringContainsString((int) DashboardProvider::THRESHOLD_GOAL.' % et plus', $text);
        $this->assertStringContainsString('sous '.(int) DashboardProvider::THRESHOLD_WARN.' %', $text);
        $this->assertStringContainsString('200 − score ÷ objectif × 100', $text, 'formule plus bas = mieux');
        $this->assertStringContainsString('Seules les évaluations soumises ou validées comptent', $text);
    }

    public function testHelpExampleIsComputedByTheRealCode(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', '/calcul-des-scores');

        // (100×3 + 60×1 + 90×1 + 120×1) ÷ 6 = 95 (le 190 % est plafonné à 120, le churn à 11/10 vaut 90 %)
        $result = $crawler->filter('[data-testid="example-result"]')->text();
        $this->assertStringContainsString('570 ÷ 6', preg_replace('/\s+/', ' ', $result));
        $this->assertStringContainsString('95 %', $result);
        $this->assertStringContainsString('plafonné', $crawler->filter('main')->text());
    }

    public function testDomainPageShowsTheBreakdownAndMatchesTheDashboard(): void
    {
        $client = $this->clientFor('manager@example.com');
        $this->seed();

        $crawler = $client->request('GET', '/d/reseau-arcep/calcul?week='.self::WEEK);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Calcul du score');

        // (100×3 + 60×1) ÷ 4 = 90
        $this->assertSame('90 %', trim($crawler->filter('[data-testid="domain-score"]')->text()));
        $formula = preg_replace('/\s+/', ' ', $crawler->filter('[data-testid="domain-formula"]')->text());
        $this->assertStringContainsString('(100 × 3 + 60 × 1) ÷ (3 + 1)', $formula);
        $this->assertStringContainsString('360 ÷ 4', $formula);
        $this->assertCount(2, $crawler->filter('tbody tr'));
        $this->assertStringContainsString('Score SCAT1', $crawler->filter('tbody')->text());

        // même chiffre que la jauge de la page du domaine
        $page = $client->request('GET', '/d/reseau-arcep?week='.self::WEEK);
        $this->assertSame(1, $page->filter('svg[role="img"][aria-label="Score du domaine : 90 %"]')->count());
    }

    public function testDomainPageFlagsCappedAndExcludedKpis(): void
    {
        $client = $this->clientFor('manager@example.com');
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $reseau = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $evaluation = (new Evaluation($reseau->getKpis()->first(), new \DateTimeImmutable(self::WEEK)))->setScore(190); // 200 %
        $evaluation->submit();
        $em->persist($evaluation);
        $extra = (new Kpi())->setCode('SCAT9')->setName('Pas encore saisi')->setDefaultTarget(10)->setPosition(3);
        $reseau->addKpi($extra);
        $em->persist($extra);
        $em->flush();

        $crawler = $client->request('GET', '/d/reseau-arcep/calcul?week='.self::WEEK);

        $this->assertStringContainsString('(plafonné)', $crawler->filter('tbody')->text());
        $this->assertStringContainsString('120 %', $crawler->filter('[data-testid="domain-score"]')->text());
        $this->assertSelectorTextContains('main', 'KPI écartés du calcul (1)');
        $this->assertSelectorTextContains('main', 'Aucune évaluation pour cette semaine');
    }

    public function testDomainPageWithoutDataExplainsWhyThereIsNoScore(): void
    {
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', '/d/reseau-arcep/calcul?week=2020-01-06');

        $this->assertResponseIsSuccessful();
        $this->assertSame('—', trim($crawler->filter('[data-testid="domain-score"]')->text()));
        $this->assertSelectorTextContains('main', 'pas de score');
    }

    public function testDomainPageFollowsTheSameAccessRulesAsTheDomain(): void
    {
        foreach (['manager@example.com' => 'finance', 'multi@example.com' => 'unknown-slug'] as $email => $slug) {
            $client = $this->clientFor($email);
            $client->request('GET', "/d/$slug/calcul");
            $this->assertResponseStatusCodeSame('unknown-slug' === $slug ? 404 : 403, $email);
        }

        $client = $this->clientFor('multi@example.com'); // lecteur en Finance : peut voir le calcul
        $client->request('GET', '/d/finance/calcul');
        $this->assertResponseIsSuccessful();

        $client = $this->clientFor('director@example.com');
        $client->request('GET', '/d/finance/calcul');
        $this->assertResponseIsSuccessful();
    }

    public function testInactiveDomainIsHiddenFromNonAdmins(): void
    {
        $client = $this->clientFor('manager@example.com');
        $container = static::getContainer();
        $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->setActive(false);
        $container->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/d/reseau-arcep/calcul');
        $this->assertResponseStatusCodeSame(404);

        $client = $this->clientFor('admin@example.com');
        $client->request('GET', '/d/reseau-arcep/calcul');
        $this->assertResponseIsSuccessful();
    }

    public function testGlobalPageListsOnlyTheUsersDomainsAndTheMean(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seed();

        $crawler = $client->request('GET', '/calcul?week='.self::WEEK);
        $this->assertResponseIsSuccessful();
        $this->assertSame('90 %', trim($crawler->filter('[data-testid="global-score"]')->text()), 'un seul domaine a des données');
        $this->assertSelectorTextContains('main', 'Domaines pris en compte (1)');
        $this->assertSelectorTextContains('main', 'Domaines sans score cette semaine (1)');
        $this->assertSelectorTextContains('main', 'Finance');
        $this->assertSelectorExists('a[href="/d/reseau-arcep/calcul?week='.self::WEEK.'"]');

        // un responsable de Réseau ne voit pas Finance
        $client = $this->clientFor('manager@example.com');
        $client->request('GET', '/calcul?week='.self::WEEK);
        $this->assertSelectorTextNotContains('main', 'Finance');
    }

    public function testDashboardAndMenuLinkToTheExplanations(): void
    {
        $client = $this->clientFor('admin@example.com');
        $crawler = $client->request('GET', '/?week='.self::WEEK);

        $this->assertSelectorExists('aside a[href="/calcul-des-scores"]', 'menu');
        $this->assertSelectorExists('main a[href="/calcul-des-scores"]', 'aide sur le tableau de bord');
        $this->assertSelectorExists('a[href="/calcul?week='.self::WEEK.'"]', 'détail de l\'atteinte globale');
        $this->assertCount(0, $crawler->filter('main a[href^="/d/"][href*="/calcul?week="]'), 'le détail par domaine est sur la page du domaine');

        $client->request('GET', '/d/reseau-arcep?week='.self::WEEK);
        $this->assertSelectorExists('main a[href="/d/reseau-arcep/calcul?week='.self::WEEK.'"]', 'détail du score du domaine');
        $this->assertSelectorExists('main a[href="/calcul-des-scores"]', 'aide sur la page du domaine');
    }
}
