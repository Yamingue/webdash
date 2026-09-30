<?php

namespace App\Twig\Components;

use App\Audit\AuditScope;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Repository\EvaluationRepository;
use App\Security\DomainVoter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('Dashboard:Sidebar', template: 'components/Dashboard/Sidebar.html.twig')]
final class Sidebar
{
    public function __construct(
        private readonly Security $security,
        private readonly DomainRepository $domains,
        private readonly EvaluationRepository $evaluations,
        private readonly AuditScope $auditScope,
    ) {
    }

    /** Le journal n'est proposé qu'à ceux qui peuvent le consulter (admin, directeur, responsables). */
    public function canViewJournal(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->auditScope->canView($user);
    }

    /** @return list<array{domain: Domain, canEvaluate: bool, canManage: bool, pending: int}> */
    public function menu(): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        return array_map(function (Domain $domain): array {
            $canManage = $this->security->isGranted(DomainVoter::MANAGE, $domain);

            return [
                'domain' => $domain,
                'canEvaluate' => $this->security->isGranted(DomainVoter::EVALUATE, $domain),
                'canManage' => $canManage,
                'pending' => $canManage ? $this->evaluations->countSubmittedForDomain($domain) : 0,
            ];
        }, $this->domains->findVisibleTo($user));
    }
}
