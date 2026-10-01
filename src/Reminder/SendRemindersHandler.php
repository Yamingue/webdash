<?php

namespace App\Reminder;

use App\Service\Week;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendRemindersHandler
{
    public function __construct(
        private readonly ReminderBuilder $builder,
        private readonly ReminderSender $sender,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendRemindersMessage $message): void
    {
        // « Maintenant » est à l'heure de l'application (Kernel, APP_TIMEZONE) : le lundi de la semaine en cours est local.
        $week = Week::resolve(null);
        $reminders = $this->builder->build($week);
        $sent = $this->sender->send($reminders, $week);

        $this->logger->info('Rappels hebdomadaires : {sent} e-mail(s) pour la semaine du {week}.', ['sent' => $sent, 'week' => $week->format('Y-m-d')]);
    }
}
