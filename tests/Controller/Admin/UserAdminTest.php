<?php

namespace App\Tests\Controller\Admin;

use App\Enum\MembershipRole;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use App\Tests\AppWebTestCase;

final class UserAdminTest extends AppWebTestCase
{
    public function testNonAdminIsForbidden(): void
    {
        $client = $this->clientFor('multi@example.com');
        $client->request('GET', '/admin/users');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesUserInSeveralDomains(): void
    {
        $client = $this->clientFor('admin@example.com');
        $container = static::getContainer();
        $domains = $container->get(DomainRepository::class);
        $reseau = $domains->findOneBy(['slug' => 'reseau-arcep']);
        $finance = $domains->findOneBy(['slug' => 'finance']);

        $crawler = $client->request('GET', '/admin/users/new');
        $this->assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['user']['email'] = 'nouveau@example.com';
        $values['user']['fullName'] = 'Nouveau';
        $values['user']['plainPassword'] = 'secret123';
        $values['user']['memberships'] = [
            ['domain' => (string) $reseau->getId(), 'role' => 'evaluator'],
            ['domain' => (string) $finance->getId(), 'role' => 'manager'],
        ];
        $client->request('POST', $form->getUri(), $values);
        $this->assertResponseRedirects('/admin/users');

        $user = $container->get(UserRepository::class)->findOneBy(['email' => 'nouveau@example.com']);
        $this->assertNotNull($user);
        $this->assertCount(2, $user->getMemberships());
        $this->assertSame(MembershipRole::Evaluator, $user->getRoleIn($reseau));
        $this->assertSame(MembershipRole::Manager, $user->getRoleIn($finance));
        $this->assertNotSame('secret123', $user->getPassword(), 'Mot de passe haché');
    }

    public function testDuplicateDomainMembershipIsRejected(): void
    {
        $client = $this->clientFor('admin@example.com');
        $reseau = static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);

        $crawler = $client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['user']['email'] = 'doublon@example.com';
        $values['user']['fullName'] = 'Doublon';
        $values['user']['plainPassword'] = 'secret123';
        $values['user']['memberships'] = [
            ['domain' => (string) $reseau->getId(), 'role' => 'evaluator'],
            ['domain' => (string) $reseau->getId(), 'role' => 'viewer'],
        ];
        $client->request('POST', $form->getUri(), $values);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testEditKeepsPasswordWhenLeftBlank(): void
    {
        $client = $this->clientFor('admin@example.com');
        $container = static::getContainer();
        $target = $container->get(UserRepository::class)->findOneBy(['email' => 'manager@example.com']);
        $hash = $target->getPassword();

        $crawler = $client->request('GET', '/admin/users/'.$target->getId().'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['user']['fullName'] = 'Responsable renommé';
        $client->request('POST', $form->getUri(), $values);
        $this->assertResponseRedirects('/admin/users');

        $fresh = $container->get(UserRepository::class)->findOneBy(['email' => 'manager@example.com']);
        $this->assertSame('Responsable renommé', $fresh->getFullName());
        $this->assertSame($hash, $fresh->getPassword());
        $this->assertCount(1, $fresh->getMemberships());
    }
}
