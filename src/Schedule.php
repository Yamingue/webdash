<?php

namespace App;

use App\Reminder\SendRemindersHandler;
use App\Reminder\SendRemindersMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tâches récurrentes. Elles ne tournent que si un worker est lancé :
 *   php bin/console messenger:consume scheduler_default async
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Rappel hebdomadaire : chaque vendredi à 9h, heure de N'Djamena.
            ->add(RecurringMessage::cron('0 9 * * 5', new SendRemindersMessage(), new \DateTimeZone(SendRemindersHandler::TIMEZONE)))
        ;
    }
}
