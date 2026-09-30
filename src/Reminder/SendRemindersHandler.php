<?php

namespace App\Reminder;

use App\Service\Week;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendRemindersHandler
{
    /** Fuseau de référence : le vendredi 9h et le « lundi » de la semaine se comptent à l'heure locale. */
    public const TIMEZONE = 'Africa/Ndjamena';

    public function __construct(
        private readonly ReminderBuilder $builder,
        private readonly ReminderSender $sender,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendRemindersMessage $message): void
    {
        $week = Week::resolve(null, new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)));
        $reminders = $this->builder->build($week);
        $sent = $this->sender->send($reminders, $week);

        $this->logger->info('Rappels hebdomadaires : {sent} e-mail(s) pour la semaine du {week}.', ['sent' => $sent, 'week' => $week->format('Y-m-d')]);
    }
}
