<?php

namespace App\Reminder;

use App\Entity\Domain;
use App\Entity\Kpi;
use App\Enum\MembershipRole;
use App\Repository\DomainRepository;
use App\Repository\EvaluationRepository;
use App\Repository\UserRepository;

/**
 * Détermine qui doit être relancé pour une semaine donnée.
 *
 * - Un évaluateur ou un responsable est relancé s'il reste, dans un de ses domaines actifs, des KPI actifs
 *   sans évaluation soumise ou validée pour la semaine (absente ou encore en brouillon).
 * - Un responsable reçoit en plus le nombre d'évaluations en attente de validation.
 * - Les lecteurs, l'admin et le directeur ne sont jamais relancés ; ceux qui ont désactivé les rappels non plus.
 */
final class ReminderBuilder
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DomainRepository $domains,
        private readonly EvaluationRepository $evaluations,
    ) {
    }

    /** @return list<Reminder> */
    public function build(\DateTimeImmutable $week): array
    {
        $domains = $this->domains->findBy(['active' => true]);
        $missingByDomain = $this->missingKpis($domains, $week);
        $pendingByDomain = [];

        $reminders = [];
        foreach ($this->users->findBy(['notifyByEmail' => true], ['fullName' => 'ASC']) as $user) {
            if ('' === trim($user->getEmail())) {
                continue;
            }

            $items = [];
            foreach ($user->getMemberships() as $membership) {
                $domain = $membership->getDomain();
                $role = $membership->getRole();
                if (null === $domain || !$domain->isActive() || !\in_array($role, [MembershipRole::Evaluator, MembershipRole::Manager], true)) {
                    continue;
                }

                $missing = $missingByDomain[$domain->getId()] ?? [];
                $pending = null;
                if (MembershipRole::Manager === $role) {
                    $pendingByDomain[$domain->getId()] ??= $this->evaluations->countSubmittedForDomain($domain);
                    $pending = $pendingByDomain[$domain->getId()];
                }

                if ([] !== $missing || ($pending ?? 0) > 0) {
                    $items[] = new DomainReminder($domain, $missing, $pending);
                }
            }

            if ([] !== $items) {
                $reminders[] = new Reminder($user, $items);
            }
        }

        return $reminders;
    }

    /**
     * @param list<Domain> $domains
     *
     * @return array<int, list<Kpi>> id de domaine => KPI actifs sans évaluation soumise/validée cette semaine
     */
    private function missingKpis(array $domains, \DateTimeImmutable $week): array
    {
        $done = [];
        foreach ($this->evaluations->findReportable($domains, $week, $week) as $evaluation) {
            $done[$evaluation->getKpi()->getId()] = true;
        }

        $missing = [];
        foreach ($domains as $domain) {
            $list = array_values(array_filter(
                $domain->getKpis()->toArray(),
                static fn (Kpi $kpi): bool => $kpi->isActive() && !isset($done[$kpi->getId()]),
            ));
            if ([] !== $list) {
                $missing[$domain->getId()] = $list;
            }
        }

        return $missing;
    }
}
