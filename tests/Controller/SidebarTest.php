<?php

namespace App\Tests\Controller;

use App\Tests\AppWebTestCase;

final class SidebarTest extends AppWebTestCase
{
    public function testAdminSeesEveryDomainAndAdminMenu(): void
    {
        $client = $this->clientFor('admin@example.com');
        $client->request('GET', '/');

        $this->assertSelectorTextContains('aside', 'Réseau & ARCEP');
        $this->assertSelectorTextContains('aside', 'Finance');
        $this->assertSelectorTextContains('aside', 'Administration');
    }

    public function testManagerOnlySeesOwnDomain(): void
    {
        $client = $this->clientFor('manager@example.com');
        $client->request('GET', '/');

        $this->assertSelectorTextContains('aside', 'Réseau & ARCEP');
        $this->assertSelectorTextNotContains('aside', 'Finance');
        $this->assertSelectorTextNotContains('aside', 'Administration');
    }

    public function testSidebarUsesTheMoovAfricaBrandColours(): void
    {
        $client = $this->clientFor('admin@example.com');
        $crawler = $client->request('GET', '/');

        $aside = $crawler->filter('aside');
        $this->assertStringContainsString('bg-moov-blue', $aside->attr('class'), 'fond bleu');
        $this->assertStringContainsString('text-white', $aside->attr('class'));
        $this->assertSelectorExists('aside .bg-moov-orange', 'logo orange');
        $this->assertSelectorTextContains('aside', 'WebDash');
        $this->assertSelectorTextContains('aside', 'Moov Africa');
    }

    public function testActiveItemIsALightTranslucentPill(): void
    {
        $client = $this->clientFor('admin@example.com');
        $crawler = $client->request('GET', '/');

        $active = $crawler->filter('aside nav a[href="/"]');
        $this->assertStringContainsString('bg-white/15', $active->attr('class'));
        $this->assertStringContainsString('before:bg-moov-orange', $active->attr('class'), 'barre orange à gauche');

        $reports = $crawler->filter('aside nav a[href="/rapports"]');
        $this->assertStringNotContainsString('bg-white/15', $reports->attr('class'));
        $this->assertStringNotContainsString('before:bg-moov-orange', $reports->attr('class'));
        $this->assertStringContainsString('hover:bg-white/10', $reports->attr('class'));

        // on change de page : l'élément actif suit
        $crawler = $client->request('GET', '/rapports');
        $this->assertStringContainsString('bg-white/15', $crawler->filter('aside nav a[href="/rapports"]')->attr('class'));
        $this->assertStringNotContainsString('bg-white/15', $crawler->filter('aside nav a[href="/"]')->attr('class'));
    }

    public function testPendingBadgeIsOrangeForManagers(): void
    {
        $client = $this->clientFor('manager@example.com');
        $container = static::getContainer();
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $kpi = $container->get(\App\Repository\DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
        $evaluation = (new \App\Entity\Evaluation($kpi, new \DateTimeImmutable('2026-09-28')))->setScore(50);
        $evaluation->submit();
        $em->persist($evaluation);
        $em->flush();

        $crawler = $client->request('GET', '/');

        $badge = $crawler->filter('aside a[aria-label*="1 en attente"] span');
        $this->assertCount(1, $badge);
        $this->assertStringContainsString('bg-moov-orange', $badge->attr('class'));
        $this->assertSame('1', trim($badge->text()));
    }

    public function testMultiDomainUserSeesBothAndEntryLinkOnlyWhereAllowed(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', '/');

        $this->assertSelectorTextContains('aside', 'Réseau & ARCEP');
        $this->assertSelectorTextContains('aside', 'Finance');
        // évaluateur en Réseau (lien de saisie), lecteur en Finance (pas de lien)
        $this->assertCount(1, $crawler->filter('aside a[href$="/saisie"]'));
        $this->assertSelectorExists('aside a[href="/d/reseau-arcep/saisie"]');
    }
}
