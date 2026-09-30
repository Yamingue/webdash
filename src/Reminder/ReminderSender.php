<?php

namespace App\Reminder;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/** Envoie les rappels par e-mail (un message par personne). Un échec n'empêche pas les envois suivants. */
final class ReminderSender
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    /**
     * @param list<Reminder> $reminders
     *
     * @return int nombre d'e-mails remis au transport (ou mis en file d'attente)
     */
    public function send(array $reminders, \DateTimeImmutable $week): int
    {
        $sent = 0;
        foreach ($reminders as $reminder) {
            $email = (new TemplatedEmail())
                ->from(Address::create($this->from))
                ->to(new Address($reminder->user->getEmail(), $reminder->user->getFullName()))
                ->subject(\sprintf('Rappel WebDash : KPI de la semaine du %s', $week->format('d/m/Y')))
                ->htmlTemplate('email/reminder.html.twig')
                ->textTemplate('email/reminder.txt.twig')
                ->context(['reminder' => $reminder, 'week' => $week]);

            try {
                $this->mailer->send($email);
                ++$sent;
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Rappel non envoyé à {email} : {error}', ['email' => $reminder->user->getEmail(), 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }
}
