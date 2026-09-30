<?php

namespace App\Tests\Controller;

use App\Enum\EvaluationStatus;
use App\Repository\DomainRepository;
use App\Repository\EvaluationRepository;
use App\Service\Week;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class EvaluationEntryTest extends AppWebTestCase
{
    private const URL = '/d/reseau-arcep/saisie';

    public function testUserOutsideDomainIsForbidden(): void
    {
        // le directeur voit tout mais n'évalue pas
        $client = $this->clientFor('director@example.com');
        $client->request('GET', self::URL);
        $this->assertResponseStatusCodeSame(403);

        // lecteur en Finance : pas de saisie
        $client = $this->clientFor('multi@example.com');
        $client->request('GET', '/d/finance/saisie');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testSaveDraftThenSubmitLocksEvaluation(): void
    {
        $client = $this->clientFor('multi@example.com');
        $container = static::getContainer();
        $week = Week::resolve(null);

        $crawler = $client->request('GET', self::URL);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Saisie hebdomadaire');

        // brouillon
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][score]'] = '90.5';
        $form['weekly_entry[evaluations][0][comment]'] = 'Bonne semaine';
        $client->submit($form);
        $this->assertResponseRedirects();
        $crawler = $client->followRedirect();

        $domain = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $evaluation = $container->get(EvaluationRepository::class)->findForDomainWeek($domain, $week);
        $this->assertCount(1, $evaluation);
        $evaluation = array_values($evaluation)[0];
        $this->assertSame(90.5, $evaluation->getScore());
        $this->assertSame(95.0, $evaluation->getTarget(), 'Objectif copié depuis la valeur par défaut');
        $this->assertSame(EvaluationStatus::Draft, $evaluation->getStatus());
        $this->assertSame(95.3, $evaluation->getAchievement());

        // soumission
        $client->submit($crawler->selectButton('Soumettre')->form());
        $this->assertResponseRedirects();
        $crawler = $client->followRedirect();

        $container->get(EntityManagerInterface::class)->clear();
        $evaluation = array_values($container->get(EvaluationRepository::class)->findAll())[0];
        $this->assertSame(EvaluationStatus::Submitted, $evaluation->getStatus());
        $this->assertSame('disabled', $crawler->filter('input[name="weekly_entry[evaluations][0][score]"]')->attr('disabled'));

        // une requête forgée ne modifie pas une évaluation verrouillée
        $values = $crawler->selectButton('Enregistrer le brouillon')->form()->getPhpValues();
        $values['weekly_entry']['evaluations'][0]['score'] = '1';
        $client->request('POST', self::URL, $values);
        $container->get(EntityManagerInterface::class)->clear();
        $this->assertSame(90.5, array_values($container->get(EvaluationRepository::class)->findAll())[0]->getScore());
    }

    public function testChangingDefaultTargetDoesNotAffectPastEvaluation(): void
    {
        $client = $this->clientFor('multi@example.com');
        $container = static::getContainer();

        $crawler = $client->request('GET', self::URL);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][score]'] = '50';
        $client->submit($form);

        $em = $container->get(EntityManagerInterface::class);
        $domain = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $domain->getKpis()->first()->setDefaultTarget(200);
        $em->flush();
        $em->clear();

        $evaluation = $container->get(EvaluationRepository::class)->findAll()[0];
        $this->assertSame(95.0, $evaluation->getTarget());
        $this->assertSame(200.0, $evaluation->getKpi()->getDefaultTarget());
    }

    public function testManagerCanAdjustTargetButEvaluatorCannot(): void
    {
        $client = $this->clientFor('multi@example.com');
        $client->request('GET', self::URL);
        $this->assertSelectorNotExists('input[name="weekly_entry[evaluations][0][target]"]');

        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', self::URL);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][target]'] = '70';
        $form['weekly_entry[evaluations][0][score]'] = '70';
        $client->submit($form);

        $evaluation = static::getContainer()->get(EvaluationRepository::class)->findAll()[0];
        $this->assertSame(70.0, $evaluation->getTarget());
        $this->assertSame(100.0, $evaluation->getAchievement());
    }

    public function testEmptySubmissionCreatesNothingAndNegativeScoreIsRejected(): void
    {
        $client = $this->clientFor('multi@example.com');
        $container = static::getContainer();

        $crawler = $client->request('GET', self::URL);
        $client->submit($crawler->selectButton('Enregistrer le brouillon')->form());
        $this->assertCount(0, $container->get(EvaluationRepository::class)->findAll());

        $crawler = $client->request('GET', self::URL);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][score]'] = '-3';
        $client->submit($form);
        $this->assertResponseStatusCodeSame(422);
        $this->assertCount(0, $container->get(EvaluationRepository::class)->findAll());
    }

    public function testAnyWeekCanBePickedAndEnteredIncludingFuture(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::URL.'?week=2030-06-05'); // un mercredi

        $this->assertResponseIsSuccessful();
        $this->assertSame('2030-06-03', $crawler->filter('input[name="week"]')->attr('value'), 'ramené au lundi');
        $this->assertSelectorTextContains('nav[aria-label="Semaine"]', 'Semaine du 03/06/2030');
        $this->assertSelectorExists('a[href$="/saisie"]:not([aria-label])', 'lien Semaine courante');

        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][score]'] = '42';
        $client->submit($form);
        $this->assertResponseRedirects(self::URL.'?week=2030-06-03');

        $evaluation = static::getContainer()->get(EvaluationRepository::class)->findAll()[0];
        $this->assertSame('2030-06-03', $evaluation->getWeekStart()->format('Y-m-d'));
    }

    public function testInvalidWeekFallsBackToCurrentWeek(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::URL.'?week=nimporte-quoi');

        $this->assertResponseIsSuccessful();
        $this->assertSame(Week::resolve(null)->format('Y-m-d'), $crawler->filter('input[name="week"]')->attr('value'));
    }
}
