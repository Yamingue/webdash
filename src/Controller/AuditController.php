<?php

namespace App\Controller;

use App\Audit\AuditAction;
use App\Audit\AuditQuery;
use App\Audit\AuditScope;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\DomainRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Journal d'audit : qui a fait quoi, limité aux domaines que l'utilisateur gère (tout pour admin/directeur). */
final class AuditController extends AbstractController
{
    private const LIMIT = 200;

    #[Route('/journal', name: 'app_audit_index', methods: ['GET'])]
    public function index(
        AuditScope $scope,
        AuditLogRepository $logs,
        DomainRepository $domains,
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] AuditQuery $query = new AuditQuery(),
    ): Response {
        $allowed = $scope->domainsFor($user);
        if (null !== $allowed && [] === $allowed) {
            throw $this->createAccessDeniedException();
        }

        $choices = $allowed ?? $domains->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
        $domain = $this->resolveDomain($query->domain, $choices);
        $action = null !== $query->action && '' !== $query->action ? AuditAction::tryFrom($query->action) : null;

        return $this->render('audit/index.html.twig', [
            'entries' => $logs->findLatest($allowed, $domain, $action, self::LIMIT),
            'domains' => $choices,
            'domain' => $domain,
            'action' => $action,
            'actions' => AuditAction::cases(),
            'limit' => self::LIMIT,
        ]);
    }

    /** @param list<Domain> $choices */
    private function resolveDomain(?string $requested, array $choices): ?Domain
    {
        if (null === $requested || '' === trim($requested)) {
            return null;
        }

        foreach ($choices as $domain) {
            if ((string) $domain->getId() === trim($requested)) {
                return $domain;
            }
        }

        throw $this->createAccessDeniedException('Domaine inaccessible.');
    }
}
