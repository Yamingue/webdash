<?php

namespace App\Tests\Integrity;

use App\Entity\Evaluation;
use App\Enum\EvaluationStatus;
use App\Repository\DomainRepository;
use App\Service\SafeFlusher;
use App\Tests\AppWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class ConcurrencyTest extends AppWebTestCase
{
    private const ENTRY = '/d/reseau-arcep/saisie?week=2026-09-28';
    private const SCORE = 'weekly_entry[evaluations][0][score]';
    private const VERSION = 'weekly_entry[evaluations][0][version]';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Crée (hors interface) l'évaluation SCAT1 de la semaine, comme le ferait un autre utilisateur. */
    private function createElsewhere(float $score): Evaluation
    {
        $kpi = static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable('2026-09-28')))->setScore($score);
        $this->em()->persist($evaluation);
        $this->em()->flush();

        return $evaluation;
    }

    private function scoreInDatabase(): ?float
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Evaluation::class)->findOneBy([])?->getScore();
    }

    private function flashes(\Symfony\Component\BrowserKit\AbstractBrowser $client): string
    {
        return $client->getCrawler()->filter('[role="status"]')->count() ? $client->getCrawler()->filter('[role="status"]')->text() : '';
    }

    public function testEachRowCarriesItsVersion(): void
    {
        $client = $this->clientFor('multi@example.com');

        $crawler = $client->request('GET', self::ENTRY);
        $this->assertSame('0', $crawler->filter('input[name="'.self::VERSION.'"]')->attr('value'), 'ligne pas encore enregistrée');

        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form[self::SCORE] = '50';
        $client->submit($form);
        $crawler = $client->request('GET', self::ENTRY);
        $this->assertSame('1', $crawler->filter('input[name="'.self::VERSION.'"]')->attr('value'));

        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form[self::SCORE] = '60';
        $client->submit($form);
        $crawler = $client->request('GET', self::ENTRY);
        $this->assertSame('2', $crawler->filter('input[name="'.self::VERSION.'"]')->attr('value'), 'la version avance à chaque modification');
    }

    public function testStaleEditIsRefusedAndDoesNotOverwriteTheOtherPersonsWork(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form[self::SCORE] = '50';
        $client->submit($form);

        // Awa ouvre la page (version 1)...
        $crawler = $client->request('GET', self::ENTRY);
        $stale = $crawler->selectButton('Enregistrer le brouillon')->form();

        // ... pendant qu'un collègue modifie la même ligne (version 2)
        $evaluation = $this->em()->getRepository(Evaluation::class)->findOneBy([]);
        $evaluation->setScore(77);
        $this->em()->flush();

        // Awa enregistre sa version périmée
        $stale[self::SCORE] = '10';
        $client->submit($stale);
        $this->assertResponseRedirects();
        $client->followRedirect();

        $this->assertStringContainsString('modifiées par quelqu\'un d\'autre', $this->flashes($client));
        $this->assertSame(77.0, $this->scoreInDatabase(), 'le travail du collègue est conservé');

        // après rechargement, la saisie suivante passe
        $crawler = $client->request('GET', self::ENTRY);
        $this->assertEquals(77, $crawler->filter('input[name="'.self::SCORE.'"]')->attr('value'), 'la page recharge la valeur du collègue');
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form[self::SCORE] = '80';
        $client->submit($form);
        $this->assertSame(80.0, $this->scoreInDatabase());
    }

    public function testTwoPeopleCreatingTheSameRowDoNotCrash(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY); // aucune ligne enregistrée : version 0
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();

        $this->createElsewhere(42); // un autre utilisateur crée la ligne entre-temps

        $form[self::SCORE] = '99';
        $client->submit($form);

        $this->assertResponseRedirects(); // pas d'erreur 500
        $client->followRedirect();
        $this->assertStringContainsString('modifiées par quelqu\'un d\'autre', $this->flashes($client));
        $this->assertSame(42.0, $this->scoreInDatabase());
        $this->assertCount(1, $this->em()->getRepository(Evaluation::class)->findAll(), 'une seule ligne pour la semaine');
    }

    public function testSubmissionOnAStaleRowDoesNotSubmit(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $stale = $crawler->selectButton('Soumettre')->form();

        $this->createElsewhere(42);
        $stale[self::SCORE] = '99';
        $client->submit($stale);

        $em = $this->em();
        $em->clear();
        $evaluation = $em->getRepository(Evaluation::class)->findOneBy([]);
        $this->assertSame(EvaluationStatus::Draft, $evaluation->getStatus(), 'rien n\'a été soumis');
        $this->assertSame(42.0, $evaluation->getScore());
    }

    public function testForgedPostWithoutVersionIsTreatedAsStale(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form[self::SCORE] = '50';
        $client->submit($form);

        $crawler = $client->request('GET', self::ENTRY);
        $values = $crawler->selectButton('Enregistrer le brouillon')->form()->getPhpValues();
        unset($values['weekly_entry']['evaluations'][0]['version']);
        $values['weekly_entry']['evaluations'][0]['score'] = '1';
        $client->request('POST', self::ENTRY, $values);

        $this->assertSame(50.0, $this->scoreInDatabase());
    }

    public function testValidationRaceIsReportedNotCrashed(): void
    {
        $container = static::getContainer();
        $client = $this->clientFor('manager@example.com');
        $evaluation = $this->createElsewhere(80);
        $evaluation->submit();
        $this->em()->flush();

        $crawler = $client->request('GET', '/d/reseau-arcep/validation');
        $form = $crawler->selectButton('Valider')->form();

        // un autre responsable rejette l'évaluation entre l'affichage et le clic
        $evaluation = $this->em()->getRepository(Evaluation::class)->findOneBy([]);
        $evaluation->reject('Trop tard');
        $this->em()->flush();

        $client->submit($form);

        // l'évaluation n'est plus « soumise » : la décision est refusée proprement (403), sans erreur serveur
        $this->assertResponseStatusCodeSame(403);
        $this->em()->clear();
        $this->assertSame(EvaluationStatus::Draft, $this->em()->getRepository(Evaluation::class)->findOneBy([])->getStatus());
    }

    public function testRealDatabaseVersionConflictIsTranslatedByTheFlusher(): void
    {
        $this->clientFor('multi@example.com');
        $container = static::getContainer();
        $em = $this->em();
        $evaluation = $this->createElsewhere(10);

        // quelqu'un d'autre écrit directement en base (la version avance sous nos pieds)
        $container->get(Connection::class)->executeStatement('UPDATE evaluation SET score = 55, version = version + 1 WHERE id = ?', [$evaluation->getId()]);

        $evaluation->setScore(11);
        $this->assertFalse($container->get(SafeFlusher::class)->flush(), 'conflit détecté, rien d\'écrit');

        $container->get(Connection::class)->executeStatement('SELECT 1'); // la connexion reste utilisable
    }
}
