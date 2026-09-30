<?php

namespace App\Tests\Audit;

use App\Audit\AuditAction;
use App\Entity\AuditLog;
use App\Entity\Evaluation;
use App\Enum\EvaluationStatus;
use App\Repository\AuditLogRepository;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use App\Service\Week;
use App\Tests\AppWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class AuditTest extends AppWebTestCase
{
    private const ENTRY = '/d/reseau-arcep/saisie';
    private const VALIDATION = '/d/reseau-arcep/validation';

    /** @return list<AuditLog> */
    private function logs(?AuditAction $action = null): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $all = static::getContainer()->get(AuditLogRepository::class)->findBy([], ['id' => 'ASC']);

        return array_values(array_filter($all, static fn (AuditLog $l): bool => null === $action || $l->getAction() === $action));
    }

    private function evaluation(string $as = 'validated'): int
    {
        $container = static::getContainer();
        $kpi = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
        $evaluation = (new Evaluation($kpi, Week::resolve(null)))->setScore(80);
        if ('draft' !== $as) {
            $evaluation->submit();
        }
        if ('validated' === $as) {
            $evaluation->validate($container->get(UserRepository::class)->findOneBy(['email' => 'manager@example.com']));
        }
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($evaluation);
        $em->flush();

        return $evaluation->getId();
    }

    private function statusOf(int $id): EvaluationStatus
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(Evaluation::class, $id)->getStatus();
    }

    // ---- journalisation automatique ----

    public function testNothingIsLoggedForDraftsOrPlainReads(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][score]'] = '70';
        $client->submit($form);

        $this->assertSame([], $this->logs(), 'un brouillon n\'est pas un événement d\'audit');
    }

    public function testSubmissionValidationRejectionAreLoggedWithTheActor(): void
    {
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $form = $crawler->selectButton('Soumettre')->form();
        $form['weekly_entry[evaluations][0][score]'] = '70';
        $client->submit($form);

        $submitted = $this->logs(AuditAction::EvaluationSubmitted);
        $this->assertCount(1, $submitted);
        $this->assertSame('Évaluateur multi-domaines', $submitted[0]->getActorName());
        $this->assertSame('reseau-arcep', $submitted[0]->getDomain()->getSlug());
        $this->assertStringContainsString('SCAT1', $submitted[0]->getSummary());
        $this->assertStringContainsString('soumise', $submitted[0]->getSummary());

        // le responsable rejette avec un motif
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', self::VALIDATION);
        $form = $crawler->selectButton('Rejeter')->form();
        $values = $form->getPhpValues();
        $values['reason'] = 'Chiffre incohérent';
        $client->request('POST', $form->getUri(), $values);

        $rejected = $this->logs(AuditAction::EvaluationRejected);
        $this->assertCount(1, $rejected);
        $this->assertSame('Responsable Réseau', $rejected[0]->getActorName());
        $this->assertStringContainsString('Chiffre incohérent', $rejected[0]->getSummary());
        $this->assertSame('Chiffre incohérent', $rejected[0]->getData()['reason']);

        // resoumission puis validation
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $client->submit($crawler->selectButton('Soumettre')->form());
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', self::VALIDATION);
        $client->submit($crawler->selectButton('Valider')->form());

        $validated = $this->logs(AuditAction::EvaluationValidated);
        $this->assertCount(1, $validated);
        $this->assertSame('Responsable Réseau', $validated[0]->getActorName());
        $this->assertCount(2, $this->logs(AuditAction::EvaluationSubmitted), 'soumise deux fois');
    }

    public function testTargetAdjustmentIsLoggedOnCreationAndOnUpdate(): void
    {
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][target]'] = '70';
        $form['weekly_entry[evaluations][0][score]'] = '65';
        $client->submit($form); // création avec un objectif différent de la valeur par défaut (95)

        $logs = $this->logs(AuditAction::EvaluationTargetChanged);
        $this->assertCount(1, $logs);
        $this->assertSame(95.0, $logs[0]->getData()['old']);
        $this->assertSame(70.0, $logs[0]->getData()['new']);
        $this->assertStringContainsString('objectif ajusté de 95 à 70', $logs[0]->getSummary());

        $crawler = $client->request('GET', self::ENTRY);
        $form = $crawler->selectButton('Enregistrer le brouillon')->form();
        $form['weekly_entry[evaluations][0][target]'] = '60';
        $client->submit($form); // mise à jour du brouillon existant

        $logs = $this->logs(AuditAction::EvaluationTargetChanged);
        $this->assertCount(2, $logs);
        $this->assertSame([70.0, 60.0], [$logs[1]->getData()['old'], $logs[1]->getData()['new']]);

        // enregistrer sans changer l'objectif n'ajoute rien
        $crawler = $client->request('GET', self::ENTRY);
        $client->submit($crawler->selectButton('Enregistrer le brouillon')->form());
        $this->assertCount(2, $this->logs(AuditAction::EvaluationTargetChanged));
    }

    public function testKpiChangesAreLoggedButUnchangedSaveIsNot(): void
    {
        $client = $this->clientFor('admin@example.com');
        $domain = static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep']);
        $url = '/admin/domains/'.$domain->getId().'/edit';

        $crawler = $client->request('GET', $url);
        $client->submit($crawler->selectButton('Enregistrer')->form());
        $this->assertSame([], $this->logs(AuditAction::KpiUpdated), 'aucune modification, aucun événement');

        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['domain']['kpis'][0]['defaultTarget'] = '80';
        $values['domain']['kpis'][0]['weight'] = '2';
        $values['domain']['kpis'][0]['lowerIsBetter'] = '1';
        $client->request('POST', $form->getUri(), $values);

        $logs = $this->logs(AuditAction::KpiUpdated);
        $this->assertCount(1, $logs);
        $this->assertSame('Admin', $logs[0]->getActorName());
        $this->assertSame('reseau-arcep', $logs[0]->getDomain()->getSlug());
        $summary = $logs[0]->getSummary();
        $this->assertStringContainsString('objectif par défaut 95 → 80', $summary);
        $this->assertStringContainsString('poids 1 → 2', $summary);
        $this->assertStringContainsString('sens plus haut = mieux → plus bas = mieux', $summary);
        $this->assertSame(['old' => 95.0, 'new' => 80.0], $logs[0]->getData()['changes']['defaultTarget']);
    }

    // ---- déverrouillage ----

    public function testManagerReopensAValidatedEvaluationWithReason(): void
    {
        $id = $this->evaluation('validated');
        $client = $this->clientFor('manager@example.com');

        $crawler = $client->request('GET', self::VALIDATION);
        $this->assertSelectorTextContains('main', 'Validées récemment');
        $form = $crawler->selectButton('Déverrouiller')->form();
        $values = $form->getPhpValues();
        $values['reason'] = 'Erreur de saisie';
        $client->request('POST', $form->getUri(), $values);
        $this->assertResponseRedirects(self::VALIDATION);

        $this->assertSame(EvaluationStatus::Draft, $this->statusOf($id));
        $logs = $this->logs(AuditAction::EvaluationReopened);
        $this->assertCount(1, $logs);
        $this->assertSame('Responsable Réseau', $logs[0]->getActorName());
        $this->assertStringContainsString('Erreur de saisie', $logs[0]->getSummary());

        // l'évaluateur voit le motif et peut corriger
        $client = $this->clientFor('multi@example.com');
        $crawler = $client->request('GET', self::ENTRY);
        $this->assertSelectorTextContains('main', 'Retour en brouillon : Erreur de saisie');
        $this->assertNull($crawler->filter('input[name="weekly_entry[evaluations][0][score]"]')->attr('disabled'));
    }

    public function testReopenRequiresAReason(): void
    {
        $id = $this->evaluation('validated');
        $client = $this->clientFor('manager@example.com');

        $crawler = $client->request('GET', self::VALIDATION);
        $form = $crawler->selectButton('Déverrouiller')->form();
        $values = $form->getPhpValues();
        $values['reason'] = '   ';
        $client->request('POST', $form->getUri(), $values);

        $this->assertSame(EvaluationStatus::Validated, $this->statusOf($id));
        $this->assertSame([], $this->logs(AuditAction::EvaluationReopened));
    }

    public function testOnlyManagersAndAdminsCanReopenAndOnlyValidatedOnes(): void
    {
        $validated = $this->evaluation('validated');

        $client = $this->clientFor('multi@example.com'); // évaluateur
        $client->request('POST', "/evaluations/$validated/reopen", ['reason' => 'x']);
        $this->assertResponseStatusCodeSame(403);

        $client = $this->clientFor('director@example.com');
        $client->request('POST', "/evaluations/$validated/reopen", ['reason' => 'x']);
        $this->assertResponseStatusCodeSame(403);

        $client = $this->clientFor('manager@example.com');
        $client->request('POST', "/evaluations/$validated/reopen", ['_token' => 'faux', 'reason' => 'x']);
        $this->assertResponseStatusCodeSame(403, 'jeton CSRF invalide');
        $this->assertSame(EvaluationStatus::Validated, $this->statusOf($validated));

        // une évaluation soumise ou en brouillon ne se « rouvre » pas
        foreach (['submitted', 'draft'] as $state) {
            static::ensureKernelShutdown();
            $client = $this->clientFor('manager@example.com');
            $other = $this->evaluationOnAnotherWeek($state);
            $client->request('POST', "/evaluations/$other/reopen", ['reason' => 'x']);
            $this->assertResponseStatusCodeSame(403, $state);
        }
    }

    private function evaluationOnAnotherWeek(string $state): int
    {
        $container = static::getContainer();
        $kpi = $container->get(DomainRepository::class)->findOneBy(['slug' => 'reseau-arcep'])->getKpis()->first();
        $week = Week::resolve(null)->modify('draft' === $state ? '-2 weeks' : '-3 weeks');
        $evaluation = (new Evaluation($kpi, $week))->setScore(10);
        if ('submitted' === $state) {
            $evaluation->submit();
        }
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($evaluation);
        $em->flush();

        return $evaluation->getId();
    }

    // ---- page du journal ----

    private function seedLogs(): void
    {
        $container = static::getContainer();
        $domains = $container->get(DomainRepository::class);
        $em = $container->get(EntityManagerInterface::class);
        $reseau = $domains->findOneBy(['slug' => 'reseau-arcep']);
        $finance = $domains->findOneBy(['slug' => 'finance']);

        $em->persist(new AuditLog(AuditAction::EvaluationValidated, 'SCAT1, semaine du 28/09/2026 : validée', null, $reseau, 'evaluation', 1));
        $em->persist(new AuditLog(AuditAction::KpiUpdated, 'SCATX : poids 1 → 2', null, $finance, 'kpi', 2));
        $em->persist(new AuditLog(AuditAction::EvaluationRejected, 'SCAT1, semaine du 21/09/2026 : rejetée', null, $reseau, 'evaluation', 3));
        $em->flush();
    }

    public function testJournalScopeAdminDirectorManagerAndOthers(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seedLogs();

        $client->request('GET', '/journal');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'SCATX : poids 1 → 2');
        $this->assertSelectorTextContains('main', 'Derniers événements (3)');
        $this->assertSelectorTextContains('main', 'Système', 'action sans utilisateur connecté');

        $client = $this->clientFor('director@example.com');
        $client->request('GET', '/journal');
        $this->assertSelectorTextContains('main', 'Derniers événements (3)');

        // le responsable de Réseau ne voit pas Finance
        $client = $this->clientFor('manager@example.com');
        $crawler = $client->request('GET', '/journal');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('main', 'Derniers événements (2)');
        $this->assertSelectorTextNotContains('main', 'SCATX');
        $this->assertSame(['', (string) $this->domainId('reseau-arcep')], $crawler->filter('#audit-domain option')->extract(['value']));

        // un évaluateur n'a pas accès
        $client = $this->clientFor('multi@example.com');
        $client->request('GET', '/journal');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testJournalFiltersAndForbiddenDomain(): void
    {
        $client = $this->clientFor('admin@example.com');
        $this->seedLogs();

        $client->request('GET', '/journal?domain='.$this->domainId('finance'));
        $this->assertSelectorTextContains('main', 'Derniers événements (1)');

        $client->request('GET', '/journal?action=evaluation.rejected');
        $this->assertSelectorTextContains('main', 'Derniers événements (1)');
        $this->assertSelectorTextContains('main', 'rejetée');

        $client->request('GET', '/journal?action=nimporte');
        $this->assertSelectorTextContains('main', 'Derniers événements (3)', 'type inconnu = pas de filtre');

        $client = $this->clientFor('manager@example.com');
        $client->request('GET', '/journal?domain='.$this->domainId('finance'));
        $this->assertResponseStatusCodeSame(403);
    }

    public function testJournalLinkInMenuOnlyForThoseWhoCanSeeIt(): void
    {
        foreach (['admin@example.com' => true, 'director@example.com' => true, 'manager@example.com' => true, 'multi@example.com' => false] as $email => $visible) {
            $client = $this->clientFor($email);
            $client->request('GET', '/');
            $visible
                ? $this->assertSelectorExists('aside a[href="/journal"]', $email)
                : $this->assertSelectorNotExists('aside a[href="/journal"]', $email);
        }
    }

    private function domainId(string $slug): int
    {
        return static::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => $slug])->getId();
    }
}
