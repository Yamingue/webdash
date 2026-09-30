<?php

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Tests\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class DashboardControllerTest extends AppWebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }

    public function testDashboardRendersForLoggedInUser(): void
    {
        $client = static::createClient();
        $this->login($client, 'admin@example.com');
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Tableau de bord');
        $this->assertSelectorTextContains('[data-sidebar-target="panel"]', 'Tableau de bord');
        $this->assertGreaterThan(0, $client->getCrawler()->filter('svg')->count());
    }

    public function testDashboardShowsIndicatorsChartAndWeekPicker(): void
    {
        $client = $this->clientFor('admin@example.com');
        $container = static::getContainer();
        $kpi = $container->get(\App\Repository\DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $evaluation = (new \App\Entity\Evaluation($kpi, new \DateTimeImmutable('2026-09-28')))->setScore(95);
        $evaluation->submit();
        $em->persist($evaluation);
        $em->flush();

        $crawler = $client->request('GET', '/?week=2026-09-30'); // un mercredi : ramené au lundi 28
        $this->assertResponseIsSuccessful();
        $this->assertSame('2026-09-28', $crawler->filter('input[name="week"]')->attr('value'));
        $this->assertSelectorTextContains('main', 'Atteinte globale');
        $this->assertSelectorTextContains('main', '100 %');
        $this->assertSelectorTextContains('main', 'Score SCAT1');
        $this->assertSelectorTextContains('main', 'Aucun KPI renseigné pour le moment'); // Finance : aucune saisie soumise
        $this->assertSelectorExists('canvas');
        $this->assertSelectorExists('[role="progressbar"]');
        // le score global est une jauge : valeur accessible + échelle annoncée
        $this->assertSelectorExists('svg[role="img"][aria-label="Atteinte globale : 100 %"]');
        // une jauge de score par domaine : Réseau à 100 %, Finance sans donnée
        $this->assertSelectorExists('svg[role="img"][aria-label="Réseau & ARCEP : 100 %"]');
        $this->assertSelectorExists('svg[role="img"][aria-label="Finance : aucune donnée"]');
        $this->assertSelectorTextContains('#domain-scores-title', 'Score par domaine');
        $this->assertSelectorExists('section[aria-labelledby="domain-scores-title"] a[href="/d/reseau-arcep"]');
        $this->assertSelectorExists('svg[role="img"] path.stroke-indigo-500', 'arc de progression');
        $this->assertSelectorExists('svg[role="img"] text');
        $this->assertSelectorTextContains('main', '1 à valider');
    }

    public function testDomainCardTabsLatestValuesAndHistory(): void
    {
        $client = $this->clientFor('admin@example.com');
        $container = static::getContainer();
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);

        // Réseau : SCAT1 (objectif 95) saisi les semaines 14/09 (50 %) et 28/09 (100 %) ;
        // SCAT2 (objectif 100) saisi une seule fois, le 07/09 (80 %).
        $reseau = $container->get(\App\Repository\DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $scat1 = $reseau->getKpis()->first();
        $scat2 = (new \App\Entity\Kpi())->setCode('SCAT2')->setName('Couverture 4G')->setDefaultTarget(100)->setPosition(1);
        $reseau->addKpi($scat2);
        $em->persist($scat2);
        foreach ([[$scat1, '2026-09-14', 47.5], [$scat1, '2026-09-28', 95], [$scat2, '2026-09-07', 80]] as [$kpi, $week, $score]) {
            $evaluation = (new \App\Entity\Evaluation($kpi, new \DateTimeImmutable($week)))->setScore($score);
            $evaluation->submit();
            $em->persist($evaluation);
        }
        $em->flush();

        $crawler = $client->request('GET', '/?week=2026-09-28');
        $card = $crawler->filter('section[aria-labelledby="domain-'.$reseau->getId().'"]');

        // onglets
        $this->assertSame('KPIs renseignés', trim($card->filter('[role="tab"]')->eq(0)->text()));
        $this->assertSame('Tous (6)', trim($card->filter('[role="tab"]')->eq(1)->text()), '3 semaines renseignées × 2 KPI');
        $this->assertSame('true', $card->filter('[role="tab"]')->eq(0)->attr('aria-selected'));
        $panels = $card->filter('[role="tabpanel"]');
        $this->assertNull($panels->eq(0)->attr('hidden'));
        $this->assertNotNull($panels->eq(1)->attr('hidden'));

        // « KPIs renseignés » : la dernière valeur de chaque KPI, même ancienne
        $latest = $panels->eq(0);
        $this->assertCount(2, $latest->filter('li'));
        $this->assertCount(2, $latest->filter('[role="progressbar"]'));
        $this->assertStringContainsString('Score SCAT1', $latest->filter('li')->eq(0)->text());
        $this->assertStringContainsString('100%', $latest->filter('li')->eq(0)->text());
        $this->assertStringNotContainsString('sem.', $latest->filter('li')->eq(0)->text(), 'valeur de la semaine choisie : pas d\'étiquette');
        $this->assertStringContainsString('Couverture 4G', $latest->filter('li')->eq(1)->text());
        $this->assertStringContainsString('80%', $latest->filter('li')->eq(1)->text());
        $this->assertStringContainsString('sem. 07/09', $latest->filter('li')->eq(1)->text(), 'valeur plus ancienne : semaine indiquée');

        // « Tous » : semaines renseignées, plus récente d'abord, tous les KPI (N/D si non saisi)
        $all = $panels->eq(1);
        $headers = $all->filter('li[role="presentation"]')->extract(['_text']);
        $this->assertSame(['Semaine du 28/09/2026', 'Semaine du 14/09/2026', 'Semaine du 07/09/2026'], array_map('trim', $headers));
        $this->assertCount(6, $all->filter('li:not([role="presentation"])'));
        $this->assertSame(3, substr_count($all->text(), 'N/D'), 'SCAT2 absent 28/09 et 14/09, SCAT1 absent 07/09');
        $this->assertCount(3, $all->filter('[role="progressbar"]'));

        // pastille d'atteinte moyenne de la semaine choisie dans l'en-tête
        $this->assertStringContainsString('100 %', $card->filter('header')->text());
    }

    public function testDomainCardWithoutAnyEntryShowsEmptyMessages(): void
    {
        $client = $this->clientFor('admin@example.com');
        $crawler = $client->request('GET', '/?week=2020-01-06');

        $first = $crawler->filter('section[data-controller="tabs"]')->first();
        $this->assertStringContainsString('Aucun KPI renseigné pour le moment', $first->filter('[role="tabpanel"]')->eq(0)->text());
        $this->assertStringContainsString('Aucune semaine renseignée pour le moment', $first->filter('[role="tabpanel"]')->eq(1)->text());
        $this->assertSame('Tous (0)', trim($first->filter('[role="tab"]')->eq(1)->text()));
    }

    public function testUserWithoutDomainSeesEmptyState(): void
    {
        self::bootKernel();
        $static = static::getContainer();
        $user = (new \App\Entity\User())->setEmail('seul@example.com')->setFullName('Seul')->setPassword('x');
        $static->get(\Doctrine\ORM\EntityManagerInterface::class)->persist($user);
        $static->get(\Doctrine\ORM\EntityManagerInterface::class)->flush();

        static::ensureKernelShutdown();
        $client = static::createClient();
        $client->loginUser($user);
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'Aucun domaine accessible');
        $this->assertSelectorNotExists('canvas');
    }

    public function testGaugeWithoutDataHasNoNeedle(): void
    {
        $client = $this->clientFor('admin@example.com');
        $client->request('GET', '/?week=2020-01-06'); // semaine sans aucune saisie

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('svg[role="img"][aria-label*="aucune donnée"]');
        $this->assertSelectorNotExists('svg[role="img"] path.stroke-indigo-500', 'aucun arc de progression sans donnée');
        $this->assertSelectorExists('svg[role="img"] path.stroke-gray-200', 'le fond reste dessiné');
        $this->assertSelectorTextContains('main', 'Aucune donnée comparable');
    }

    public function testFormLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'manager@example.com', '_password' => 'password']);

        $this->assertResponseRedirects('/');
        $client->followRedirect();
        $this->assertSelectorExists('[data-sidebar-target="panel"]');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');
        $client->submitForm('Se connecter', ['_username' => 'manager@example.com', '_password' => 'nope']);
        $client->followRedirect();

        $this->assertSelectorExists('[role="alert"]');
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        $client->loginUser($user);
    }
}
