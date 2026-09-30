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
