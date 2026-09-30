<?php

namespace App\Audit;

use App\Entity\Domain;
use App\Entity\User;
use App\Enum\MembershipRole;

/**
 * Qui peut consulter le journal, et sur quels domaines :
 * admin et directeur voient tout ; un responsable voit les domaines qu'il gère ; les autres rien.
 */
final class AuditScope
{
    /** @return list<Domain>|null null = tous les domaines (et les lignes sans domaine) ; liste vide = aucun accès */
    public function domainsFor(User $user): ?array
    {
        if ($user->hasGlobalAccess()) {
            return null;
        }

        $domains = [];
        foreach ($user->getMemberships() as $membership) {
            if (MembershipRole::Manager === $membership->getRole() && null !== $membership->getDomain()) {
                $domains[] = $membership->getDomain();
            }
        }

        return $domains;
    }

    public function canView(User $user): bool
    {
        return null === $this->domainsFor($user) || [] !== $this->domainsFor($user);
    }
}
