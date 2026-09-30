<?php

namespace App\Tests;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Base des tests fonctionnels : connexion rapide avec les comptes des fixtures. */
abstract class AppWebTestCase extends WebTestCase
{
    protected function clientFor(string $email): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, "Utilisateur $email absent : chargez les fixtures en env test.");
        $client->loginUser($user);

        return $client;
    }
}
