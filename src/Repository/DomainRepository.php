<?php

namespace App\Repository;

use App\Entity\Domain;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Domain> */
class DomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Domain::class);
    }

    /**
     * Domaines actives visibles par l'utilisateur (menu) : toutes pour admin/directeur,
     * sinon celles où il a un rattachement.
     *
     * @return list<Domain>
     */
    public function findVisibleTo(User $user): array
    {
        if ($user->hasGlobalAccess()) {
            return $this->findBy(['active' => true], ['position' => 'ASC', 'name' => 'ASC']);
        }

        $domains = [];
        foreach ($user->getMemberships() as $membership) {
            $domain = $membership->getDomain();
            if ($domain?->isActive()) {
                $domains[] = $domain;
            }
        }
        usort($domains, static fn (Domain $a, Domain $b): int => [$a->getPosition(), $a->getName()] <=> [$b->getPosition(), $b->getName()]);

        return $domains;
    }
}
