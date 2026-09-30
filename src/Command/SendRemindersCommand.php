<?php

namespace App\Command;

use App\Reminder\ReminderBuilder;
use App\Reminder\ReminderSender;
use App\Service\Week;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:send-reminders', description: 'Envoie les rappels hebdomadaires (KPI non saisis, évaluations à valider)')]
final class SendRemindersCommand
{
    public function __construct(
        private readonly ReminderBuilder $builder,
        private readonly ReminderSender $sender,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Une date (AAAA-MM-JJ) de la semaine visée ; par défaut la semaine en cours')] ?string $week = null,
        #[Option('Affiche les destinataires sans rien envoyer')] bool $dryRun = false,
    ): int {
        $monday = Week::resolve($week);
        $reminders = $this->builder->build($monday);

        $io->title(\sprintf('Rappels pour la semaine du %s', $monday->format('d/m/Y')));

        if ([] === $reminders) {
            $io->success('Personne à relancer.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Destinataire', 'E-mail', 'KPI manquants', 'À valider'],
            array_map(static fn ($r): array => [$r->user->getFullName(), $r->user->getEmail(), $r->missingCount(), $r->pendingCount()], $reminders),
        );

        if ($dryRun) {
            $io->note('Mode --dry-run : aucun e-mail envoyé.');

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d e-mail(s) envoyé(s).', $this->sender->send($reminders, $monday)));

        return Command::SUCCESS;
    }
}
