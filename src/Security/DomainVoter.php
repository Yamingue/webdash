<?php

namespace App\Security;

use App\Entity\Domain;
use App\Entity\User;
use App\Enum\MembershipRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un domaine :
 * - admin : tout ; directeur : lecture seule sur toutes les domaines
 * - sinon, selon le rôle du membre dans ce domaine.
 *
 * @extends Voter<string, Domain>
 */
final class DomainVoter extends Voter
{
    public const VIEW = 'DOMAIN_VIEW';
    public const EVALUATE = 'DOMAIN_EVALUATE';
    public const MANAGE = 'DOMAIN_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Domain
            && \in_array($attribute, [self::VIEW, self::EVALUATE, self::MANAGE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->hasGlobalAccess()) {
            return self::VIEW === $attribute;
        }

        $role = $user->getRoleIn($subject);
        if (null === $role) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::EVALUATE => \in_array($role, [MembershipRole::Manager, MembershipRole::Evaluator], true),
            self::MANAGE => MembershipRole::Manager === $role,
            default => false,
        };
    }
}
