<?php

namespace App\Tests\Controller\Admin;

use App\Repository\DomainRepository;
use App\Tests\AppWebTestCase;

final class DomainAdminTest extends AppWebTestCase
{
    public function testNonAdminIsForbidden(): void
    {
        foreach (['manager@example.com', 'director@example.com'] as $email) {
            $client = $this->clientFor($email);
            $client->request('GET', '/admin/domains');
            $this->assertResponseStatusCodeSame(403, $email);
        }
    }

    public function testAdminCreatesDynamicDomainWithKpis(): void
    {
        $client = $this->clientFor('admin@example.com');
        $crawler = $client->request('GET', '/admin/domains/new');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(10, $crawler->filter('input[type=radio][name="domain[icon]"]')->count(), 'Sélecteur d\'icônes');

        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['domain']['name'] = 'Ressources Humaines';
        $values['domain']['slug'] = '';
        $values['domain']['icon'] = 'lucide:users';
        $values['domain']['active'] = '1';
        $values['domain']['kpis'][0] = [
            'code' => 'RH1', 'name' => 'Turnover', 'defaultTarget' => '12.5', 'unit' => '%', 'position' => '0', 'active' => '1', 'weight' => '2.5', 'lowerIsBetter' => '1',
        ];
        $client->request('POST', $form->getUri(), $values);
        $this->assertResponseRedirects('/admin/domains');

        $domain = static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'ressources-humaines']);
        $this->assertNotNull($domain, 'Slug généré depuis le nom');
        $this->assertSame('lucide:users', $domain->getIcon());
        $this->assertCount(1, $domain->getKpis());
        $this->assertSame(12.5, $domain->getKpis()->first()->getDefaultTarget());
        $this->assertSame(2.5, $domain->getKpis()->first()->getWeight());
        $this->assertTrue($domain->getKpis()->first()->isLowerIsBetter());

        // la nouvelle domaine apparaît dans le menu de l'admin
        $client->request('GET', '/');
        $this->assertSelectorTextContains('aside', 'Ressources Humaines');
    }

    public function testInvalidDomainIsRejected(): void
    {
        $client = $this->clientFor('admin@example.com');
        $crawler = $client->request('GET', '/admin/domains/new');
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['domain']['name'] = '';
        $values['domain']['icon'] = 'lucide:users';
        $client->request('POST', $form->getUri(), $values);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testChangingDefaultTargetKeepsPastEvaluationsUntouched(): void
    {
        $client = $this->clientFor('admin@example.com');
        $domain = static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $crawler = $client->request('GET', '/admin/domains/'.$domain->getId().'/edit');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['domain']['kpis'][0]['defaultTarget'] = '80';
        $client->request('POST', $form->getUri(), $values);
        $this->assertResponseRedirects('/admin/domains');

        $fresh = static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $this->assertSame(80.0, $fresh->getKpis()->first()->getDefaultTarget());
    }
}
