<?php

namespace App\Tests\Reminder;

use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\Membership;
use App\Entity\User;
use App\Enum\MembershipRole;
use App\Reminder\Reminder;
use App\Reminder\ReminderBuilder;
use App\Reminder\ReminderSender;
use App\Reminder\SendRemindersMessage;
use App\Repository\DomainRepository;
use App\Repository\UserRepository;
use App\Schedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;

final class ReminderTest extends KernelTestCase
{
    private const WEEK = '2026-09-28';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function week(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::WEEK);
    }

    private function user(string $email): User
    {
        return self::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    private function domain(string $slug): \App\Entity\Domain
    {
        return self::getContainer()->get(DomainRepository::class)->findOneBy(['slug' => $slug]);
    }

    private function evaluate(Kpi $kpi, string $week, bool $submit = true): Evaluation
    {
        $evaluation = (new Evaluation($kpi, new \DateTimeImmutable($week)))->setScore(50);
        if ($submit) {
            $evaluation->submit();
        }
        $this->em->persist($evaluation);
        $this->em->flush();

        return $evaluation;
    }

    /** @return array<string, Reminder> indexés par e-mail */
    private function reminders(?\DateTimeImmutable $week = null): array
    {
        $list = [];
        foreach (self::getContainer()->get(ReminderBuilder::class)->build($week ?? $this->week()) as $reminder) {
            $list[$reminder->user->getEmail()] = $reminder;
        }

        return $list;
    }

    // ---- règle : qui est relancé ----

    public function testEvaluatorsAndManagersWithMissingKpisAreReminded(): void
    {
        $reminders = $this->reminders();

        // Réseau : SCAT1 non saisi → évaluateur (multi) et responsable (manager) relancés
        $this->assertSame(['manager@example.com', 'multi@example.com'], array_keys($reminders));
        $this->assertSame(1, $reminders['multi@example.com']->missingCount());
        $this->assertSame('SCAT1', $reminders['multi@example.com']->domains[0]->missing[0]->getCode());
        $this->assertNull($reminders['multi@example.com']->domains[0]->pending, 'pas de file de validation pour un évaluateur');
        $this->assertSame(0, $reminders['manager@example.com']->domains[0]->pending);
    }

    public function testAdminDirectorAndViewersAreNeverReminded(): void
    {
        $emails = array_keys($this->reminders());

        $this->assertNotContains('admin@example.com', $emails);
        $this->assertNotContains('director@example.com', $emails);
        // multi est lecteur en Finance : Finance n'apparaît pas dans son rappel
        $multi = $this->reminders()['multi@example.com'];
        $this->assertSame(['reseau-arcep'], array_map(static fn ($d) => $d->domain->getSlug(), $multi->domains));
    }

    public function testNoReminderOnceTheWeekIsSubmittedButDraftsStillCount(): void
    {
        $kpi = $this->domain('reseau-arcep')->getKpis()->first();

        $this->evaluate($kpi, self::WEEK, submit: false);
        $this->assertArrayHasKey('multi@example.com', $this->reminders(), 'un brouillon n\'est pas une saisie soumise');

        $evaluation = $this->em->getRepository(Evaluation::class)->findOneBy([]);
        $evaluation->submit();
        $this->em->flush();
        // tout est soumis : l'évaluateur n'a plus rien à saisir, mais le responsable a une évaluation à valider
        $this->assertSame(['manager@example.com'], array_keys($this->reminders()));
        $this->assertSame(1, $this->reminders()['manager@example.com']->pendingCount());

        $evaluation->validate($this->user('manager@example.com'));
        $this->em->flush();
        $this->assertSame([], $this->reminders(), 'soumise puis validée : plus personne à relancer');
    }

    public function testOtherWeeksDoNotCount(): void
    {
        $this->evaluate($this->domain('reseau-arcep')->getKpis()->first(), '2026-09-21'); // semaine précédente

        $this->assertArrayHasKey('multi@example.com', $this->reminders(), 'la saisie de la semaine précédente ne dispense pas');
    }

    public function testManagerIsRemindedForPendingValidationsEvenWithNothingMissing(): void
    {
        $kpi = $this->domain('reseau-arcep')->getKpis()->first();
        $this->evaluate($kpi, self::WEEK);          // soumise cette semaine : rien ne manque
        $this->evaluate($kpi, '2026-09-21');        // soumise, en attente de validation

        $reminders = $this->reminders();

        $this->assertSame(['manager@example.com'], array_keys($reminders), 'l\'évaluateur n\'a plus rien à saisir');
        $this->assertSame(0, $reminders['manager@example.com']->missingCount());
        $this->assertSame(2, $reminders['manager@example.com']->pendingCount());
    }

    public function testOptOutInactiveDomainAndInactiveKpiAreRespected(): void
    {
        $multi = $this->user('multi@example.com');
        $multi->setNotifyByEmail(false);
        $this->em->flush();
        $this->assertArrayNotHasKey('multi@example.com', $this->reminders(), 'rappels désactivés par l\'utilisateur');
        $this->assertArrayHasKey('manager@example.com', $this->reminders());

        $this->domain('reseau-arcep')->getKpis()->first()->setActive(false);
        $this->em->flush();
        $this->assertSame([], $this->reminders(), 'un KPI inactif n\'est jamais réclamé');

        $this->domain('reseau-arcep')->getKpis()->first()->setActive(true);
        $this->domain('reseau-arcep')->setActive(false);
        $this->em->flush();
        $this->assertSame([], $this->reminders(), 'un domaine inactif est ignoré');
    }

    public function testOneReminderPerUserGroupsAllHisDomains(): void
    {
        $multi = $this->user('multi@example.com');
        $finance = $this->domain('finance');
        foreach ($multi->getMemberships() as $membership) {
            if ($membership->getDomain() === $finance) {
                $membership->setRole(MembershipRole::Evaluator); // était lecteur
            }
        }
        $this->em->flush();

        $multi = $this->reminders()['multi@example.com'];
        $this->assertCount(2, $multi->domains);
        $this->assertSame(2, $multi->missingCount());
    }

    // ---- e-mail ----

    public function testSenderBuildsOneTemplatedEmailPerPerson(): void
    {
        $reminders = array_values($this->reminders());
        $sent = self::getContainer()->get(ReminderSender::class)->send($reminders, $this->week());

        $this->assertSame(2, $sent);
        $messages = self::getMailerMessages();
        $this->assertCount(2, $messages);

        /** @var Email $email */
        $email = array_values(array_filter($messages, static fn (Email $m): bool => 'multi@example.com' === $m->getTo()[0]->getAddress()))[0];
        $this->assertSame('Rappel WebDash : KPI de la semaine du 28/09/2026', $email->getSubject());
        $this->assertSame('Évaluateur multi-domaines', $email->getTo()[0]->getName());
        $this->assertSame('no-reply@webdash.local', $email->getFrom()[0]->getAddress());

        $html = $email->getHtmlBody();
        $this->assertStringContainsString('Réseau &amp; ARCEP', $html);
        $this->assertStringContainsString('Score SCAT1', $html);
        $this->assertStringContainsString('/d/reseau-arcep/saisie?week=2026-09-28', $html, 'lien direct vers la saisie de la bonne semaine');
        $this->assertStringContainsString('Saisir les KPI', $html);
        $this->assertStringNotContainsString('Valider', $html, 'pas de bouton de validation pour un évaluateur');

        $text = $email->getTextBody();
        $this->assertStringContainsString('Score SCAT1', $text);
        $this->assertStringContainsString('/d/reseau-arcep/saisie?week=2026-09-28', $text);

        $manager = array_values(array_filter($messages, static fn (Email $m): bool => 'manager@example.com' === $m->getTo()[0]->getAddress()))[0];
        $this->assertStringNotContainsString('en attente de votre validation', $manager->getHtmlBody(), 'rien à valider : pas de section');
    }

    public function testManagerEmailMentionsPendingValidationsWithLink(): void
    {
        $kpi = $this->domain('reseau-arcep')->getKpis()->first();
        $this->evaluate($kpi, self::WEEK);
        $this->evaluate($kpi, '2026-09-21');

        self::getContainer()->get(ReminderSender::class)->send(array_values($this->reminders()), $this->week());

        $html = self::getMailerMessages()[0]->getHtmlBody();
        $this->assertStringContainsString('2</strong> évaluation(s) en attente de votre validation', $html);
        $this->assertStringContainsString('/d/reseau-arcep/validation', $html);
        $this->assertStringNotContainsString('KPI sans évaluation soumise', $html, 'rien ne manque');
    }

    // ---- commande ----

    private function command(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:send-reminders'));
    }

    public function testCommandDryRunListsRecipientsWithoutSending(): void
    {
        $tester = $this->command();
        $tester->execute(['--week' => self::WEEK, '--dry-run' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('semaine du 28/09/2026', $display);
        $this->assertStringContainsString('manager@example.com', $display);
        $this->assertStringContainsString('multi@example.com', $display);
        $this->assertStringContainsString('aucun e-mail envoyé', $display);
        $this->assertCount(0, self::getMailerMessages());
    }

    public function testCommandSendsEmails(): void
    {
        $tester = $this->command();
        $tester->execute(['--week' => '2026-09-30']); // un mercredi : ramené au lundi 28/09

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('2 e-mail(s) envoyé(s)', $tester->getDisplay());
        $this->assertCount(2, self::getMailerMessages());
    }

    public function testCommandWithNobodyToRemind(): void
    {
        $kpi = $this->domain('reseau-arcep')->getKpis()->first();
        $this->evaluate($kpi, self::WEEK)->validate($this->user('manager@example.com')); // validée : rien à saisir, rien à valider
        $this->em->flush();

        $tester = $this->command();
        $tester->execute(['--week' => self::WEEK]);

        $this->assertStringContainsString('Personne à relancer', $tester->getDisplay());
        $this->assertCount(0, self::getMailerMessages());
    }

    // ---- planification ----

    public function testScheduleSendsTheReminderEveryFridayAt9InNdjamena(): void
    {
        $schedule = (new Schedule(self::getContainer()->get('cache.app')))->getSchedule();

        $recurring = $schedule->getRecurringMessages();
        $this->assertCount(2, $recurring, "sauvegarde nocturne + rappel hebdomadaire");

        $tz = new \DateTimeZone('Africa/Ndjamena');
        $wednesday = new \DateTimeImmutable('2026-09-30 10:00', $tz);

        // sauvegarde : chaque nuit à 2h
        $this->assertSame("Thu 2026-10-01 02:00", $recurring[0]->getTrigger()->getNextRunDate($wednesday)->setTimezone($tz)->format("D Y-m-d H:i"));

        // rappel : chaque vendredi à 9h
        $next = $recurring[1]->getTrigger()->getNextRunDate($wednesday);
        $this->assertSame("Fri 2026-10-02 09:00", $next->setTimezone($tz)->format("D Y-m-d H:i"));
        $afterFriday = $recurring[1]->getTrigger()->getNextRunDate(new \DateTimeImmutable('2026-10-02 09:00', $tz));
        $this->assertSame("2026-10-09 09:00", $afterFriday->setTimezone($tz)->format("Y-m-d H:i"), "la semaine suivante");
    }

    public function testScheduledMessageIsHandledAndSendsTheReminders(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SendRemindersMessage());

        // aucune évaluation dans la base de test : évaluateur et responsable de Réseau sont relancés
        $this->assertCount(2, self::getMailerMessages());
    }
}
