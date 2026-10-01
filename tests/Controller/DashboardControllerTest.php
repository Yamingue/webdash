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

    public function testDashboardShowsGlobalIndicatorsAndWeekPicker(): void
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
        $this->assertSelectorTextContains('main', 'Score global pondéré');
        $this->assertSelectorTextContains('main', '100 %');
        $this->assertSelectorTextContains('main', 'Score SCAT1');
        $this->assertSelectorTextContains('main', 'Aucun KPI renseigné pour le moment'); // Finance : aucune saisie soumise
        $this->assertSelectorExists('[role="progressbar"]');
        $this->assertSelectorNotExists('canvas', 'plus de courbe d’évolution sur le tableau de bord');
        // le score global est une jauge : valeur accessible + échelle annoncée
        $this->assertSelectorExists('svg[role="img"][aria-label="Score global pondéré : 100 %"]');
        // le score par domaine n'est plus sur le tableau de bord : une seule jauge (l'atteinte globale)
        $this->assertCount(1, $crawler->filter('svg[role="img"]'));
        $this->assertSelectorNotExists('#domain-scores-title');
        $this->assertSelectorNotExists('svg[role="img"][aria-label^="Réseau"]');
        $this->assertSelectorExists('svg[role="img"] path.stroke-emerald-500', 'arc de progression, coloré selon le seuil (100 % : vert)');
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

    /** Réseau : SCAT1 (objectif 95) à 100 % (vert), R1 à 40 % (rouge), A1 à 90 % (ambre), M1 non saisi ; Finance : SCATX non saisi. */
    private function seedStatuses(): void
    {
        $container = static::getContainer();
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $reseau = $container->get(\App\Repository\DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);

        $kpis = ['SCAT1' => $reseau->getKpis()->first()];
        foreach (['R1' => 1, 'A1' => 2, 'M1' => 3] as $code => $position) {
            $kpi = (new \App\Entity\Kpi())->setCode($code)->setName($code)->setDefaultTarget(100)->setPosition($position);
            $reseau->addKpi($kpi);
            $em->persist($kpi);
            $kpis[$code] = $kpi;
        }
        $em->flush();

        foreach (['SCAT1' => 95, 'R1' => 40, 'A1' => 90] as $code => $score) {
            $evaluation = (new \App\Entity\Evaluation($kpis[$code], new \DateTimeImmutable('2026-09-28')))->setScore($score);
            $evaluation->submit();
            $em->persist($evaluation);
        }
        $em->flush();
    }

    public function testStatusCardsCountEveryKpiByColour(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seedStatuses();

        $crawler = $client->request('GET', '/?week=2026-09-28');
        $this->assertResponseIsSuccessful();

        $cards = $crawler->filter('[data-status-card]');
        $this->assertCount(4, $cards, 'rouges, ambre, verts, non renseignés');

        $expected = [
            ['red', '1', 'KPI rouges', 'Action urgente', 'bg-red-100'],
            ['amber', '1', 'KPI ambre', 'Surveillance', 'bg-amber-100'],
            ['green', '1', 'KPI verts', 'Objectif atteint', 'bg-green-100'],
            ['missing', '2', 'Non renseignés', 'Données manquantes', 'bg-white'],
        ];
        foreach ($expected as $i => [$status, $count, $label, $subtitle, $background]) {
            $card = $cards->eq($i);
            $text = preg_replace('/\s+/', ' ', $card->text());
            $this->assertSame($status, $card->attr('data-status-card'));
            $this->assertStringStartsWith($count.' ', $text, $label);
            $this->assertStringContainsString($label, $text);
            $this->assertStringContainsString($subtitle, $text);
            $this->assertStringContainsString($background, $card->attr('class'), 'fond teinté selon le statut');
            $this->assertStringContainsString('border-t-4', $card->attr('class'), 'filet supérieur');
        }

        // répartition par domaine dans la carte « Non renseignés »
        $this->assertStringContainsString('Réseau & ARCEP 1 · Finance 1', preg_replace('/\s+/', ' ', $cards->eq(3)->text()));

        // les anciens compteurs ont disparu
        $this->assertSelectorTextNotContains('main', 'KPI évalués');
        $this->assertSelectorTextNotContains('main', 'KPI sans saisie');
    }

    public function testGlobalScoreIsAToneHalfGaugeLikeTheStatusRow(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seedStatuses();

        $crawler = $client->request('GET', '/?week=2026-09-28');

        // Réseau seul a des données : (100 + 40 + 90) ÷ 3 = 76,7 → sous 80 % : rouge
        $this->assertCount(1, $crawler->filter('svg[role="img"][aria-label="Score global pondéré : 76.7 %"]'));
        $this->assertSelectorExists('svg[aria-label^="Score global pondéré"] path.stroke-rose-500', 'arc coloré selon le seuil');
        $this->assertSame('4 4 92 52', $crawler->filter('svg[aria-label^="Score global pondéré"]')->attr('viewBox'), 'demi-cercle');
        $this->assertSelectorTextContains('main', 'Voir le calcul');
    }

    public function testNoKpiDetailTableAnymore(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seedStatuses();

        $client->request('GET', '/?week=2026-09-28');

        $this->assertSelectorTextNotContains('main', 'Détail des KPI');
        $this->assertSelectorNotExists('[data-controller="status-filter"]');
        $this->assertSelectorNotExists('main button[data-status-card]', 'les cartes ne sont plus des filtres');
    }

    public function testStatusCardsStayInTheUsersScope(): void
    {
        $client = $this->clientFor('manager@example.com'); // responsable de Réseau seulement
        $this->seedStatuses();

        $crawler = $client->request('GET', '/?week=2026-09-28');

        $cards = $crawler->filter('[data-status-card]');
        $sum = array_sum($cards->each(static fn ($c) => (int) preg_replace('/\D.*$/s', '', trim($c->text()))));
        $this->assertSame(4, $sum, 'les 4 KPI de Réseau, pas ceux de Finance');
        $this->assertStringNotContainsString('Finance', $cards->eq(3)->text());
    }

    public function testStatusRowAdaptsToTheSelectedWeek(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seedStatuses();

        $crawler = $client->request('GET', '/?week=2020-01-06'); // semaine sans aucune saisie

        $cards = $crawler->filter('[data-status-card]');
        $this->assertSame(
            ['0', '0', '0', '5'],
            array_map(static fn ($t) => preg_replace('/\D.*$/s', '', trim($t)), $cards->each(static fn ($c) => $c->text())),
            'tous les KPI sont non renseignés',
        );
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
