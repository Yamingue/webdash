<?php

namespace App\Tests\Controller;

use App\Entity\Evaluation;
use App\Enum\EvaluationStatus;
use App\Repository\DomainRepository;
use App\Repository\EvaluationRepository;
use App\Repository\UserRepository;
use App\Service\Week;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ValidationControllerTest extends AppWebTestCase
{
    private const PAGE = '/d/reseau-arcep/validation';

    /** Crée une évaluation soumise par l'évaluateur (hors UI) et renvoie son id. */
    private function submittedEvaluation(float $score = 80.0): int
    {
        $container = static::getContainer();
        $kpi = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
        $author = $container->get(UserRepository::class)->findOneBy(['email' => 'multi@example.com']);

        $evaluation = (new Evaluation($kpi, Week::resolve(null)))->setScore($score)->setCreatedBy($author)->setComment('RAS');
        $evaluation->submit();
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($evaluation);
        $em->flush();

        return $evaluation->getId();
    }

    private function reload(int $id): Evaluation
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Evaluation::class, $id);
    }

    public function testOnlyManagersAndAdminsOpenTheValidationPage(): void
    {
        foreach (['multi@example.com' => 403, 'director@example.com' => 403, 'manager@example.com' => 200, 'admin@example.com' => 200] as $email => $status) {
            $client = $this->clientFor($email);
            $client->request('GET', self::PAGE);
            $this->assertResponseStatusCodeSame($status, $email);
        }
    }

    public function testManagerValidatesSubmittedEvaluation(): void
    {
        $client = $this->clientFor('manager@example.com');
        $id = $this->submittedEvaluation(90);

        $crawler = $client->request('GET', self::PAGE);
        $this->assertSelectorTextContains('table', 'SCAT1');
        $this->assertSelectorTextContains('table', 'Évaluateur multi-domaines');

        $client->submit($crawler->selectButton('Valider')->form());
        $this->assertResponseRedirects(self::PAGE);

        $evaluation = $this->reload($id);
        $this->assertSame(EvaluationStatus::Validated, $evaluation->getStatus());
        $this->assertSame('manager@example.com', $evaluation->getValidatedBy()->getEmail());

        $client->followRedirect();
        $this->assertSelectorTextContains('[role="status"]', 'validé');
        $this->assertSelectorTextContains('main', 'Aucune évaluation en attente');
    }

    public function testRejectNeedsReasonThenSendsBackToEvaluator(): void
    {
        $client = $this->clientFor('manager@example.com');
        $id = $this->submittedEvaluation();

        $crawler = $client->request('GET', self::PAGE);
        $form = $crawler->selectButton('Rejeter')->form();

        // sans motif : refusé
        $values = $form->getPhpValues();
        $values['reason'] = '   ';
        $client->request('POST', $form->getUri(), $values);
        $this->assertResponseRedirects(self::PAGE);
        $this->assertSame(EvaluationStatus::Submitted, $this->reload($id)->getStatus());

        // avec motif
        $crawler = $client->request('GET', self::PAGE);
        $form = $crawler->selectButton('Rejeter')->form();
        $values = $form->getPhpValues();
        $values['reason'] = 'Chiffre incohérent';
        $client->request('POST', $form->getUri(), $values);
        $this->assertResponseRedirects(self::PAGE);

        $evaluation = $this->reload($id);
        $this->assertSame(EvaluationStatus::Draft, $evaluation->getStatus());
        $this->assertSame('Chiffre incohérent', $evaluation->getRejectionReason());

        // l'évaluateur voit le motif et peut corriger
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', '/d/reseau-arcep/saisie');
        $this->assertSelectorTextContains('main', 'Retour en brouillon : Chiffre incohérent');
        $this->assertNull($crawler->filter('input[name="weekly_entry[evaluations][0][score]"]')->attr('disabled'));
    }

    public function testEvaluatorCannotValidateOrReject(): void
    {
        $id = $this->submittedEvaluation();
        $client = $this->clientFor('multi@example.com');

        $client->request('POST', "/evaluations/$id/validate");
        $this->assertResponseStatusCodeSame(403);
        $client->request('POST', "/evaluations/$id/reject", ['reason' => 'non']);
        $this->assertResponseStatusCodeSame(403);

        $this->assertSame(EvaluationStatus::Submitted, $this->reload($id)->getStatus());
    }

    public function testValidatedEvaluationCannotBeReviewedAgain(): void
    {
        $id = $this->submittedEvaluation();
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', self::PAGE);
        $client->submit($crawler->selectButton('Valider')->form());
        $this->assertSame(EvaluationStatus::Validated, $this->reload($id)->getStatus());

        $client->request('POST', "/evaluations/$id/reject", ['reason' => 'trop tard']);
        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(EvaluationStatus::Validated, $this->reload($id)->getStatus());
    }

    public function testInvalidCsrfTokenIsRejected(): void
    {
        $id = $this->submittedEvaluation();
        $client = $this->clientFor('manager@example.com');
        $client->request('POST', "/evaluations/$id/validate", ['_token' => 'faux']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame(EvaluationStatus::Submitted, $this->reload($id)->getStatus());
    }

    public function testValidateAll(): void
    {
        $client = $this->clientFor('manager@example.com');
        $id = $this->submittedEvaluation();

        $crawler = $client->request('GET', self::PAGE);
        $client->submit($crawler->selectButton('Tout valider (1)')->form());
        $this->assertResponseRedirects(self::PAGE);

        $this->assertSame(EvaluationStatus::Validated, $this->reload($id)->getStatus());
        $this->assertSame(0, static::getContainer()->get(EvaluationRepository::class)->countSubmittedForDomain(
            static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])
        ));
    }

    public function testSidebarShowsPendingCountOnlyToManagers(): void
    {
        $this->submittedEvaluation();

        $client = $this->clientFor('manager@example.com');
        $client->request('GET', '/');
        $this->assertSelectorExists('aside a[aria-label*="1 en attente"]');

        $client = $this->clientFor('multi@example.com');
        $client->request('GET', '/');
        $this->assertSelectorNotExists('aside a[href$="/validation"]');
    }

    public function testValidatedEvaluationIsLockedInWeeklyEntry(): void
    {
        $id = $this->submittedEvaluation();
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', self::PAGE);
        $client->submit($crawler->selectButton('Valider')->form());

        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', '/d/reseau-arcep/saisie');
        $this->assertSame('disabled', $crawler->filter('input[name="weekly_entry[evaluations][0][score]"]')->attr('disabled'));
        $this->assertSelectorTextContains('main', 'Validée');
        $this->assertSame(EvaluationStatus::Validated, $this->reload($id)->getStatus());
    }
}
