<?php

namespace App\Reminder;

use App\Entity\User;

/** Le rappel d'une personne pour une semaine : un seul e-mail qui regroupe tous ses domaines. */
final readonly class Reminder
{
    /** @param list<DomainReminder> $domains */
    public function __construct(
        public User $user,
        public array $domains,
    ) {
    }

    public function missingCount(): int
    {
        return array_sum(array_map(static fn (DomainReminder $d): int => \count($d->missing), $this->domains));
    }

    public function pendingCount(): int
    {
        return array_sum(array_map(static fn (DomainReminder $d): int => $d->pending ?? 0, $this->domains));
    }
}
