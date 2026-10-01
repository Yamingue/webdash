<?php

namespace App\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Enregistre en base en transformant les conflits d'écriture concurrente en résultat exploitable
 * (au lieu d'une erreur 500) :
 * - ligne modifiée entre-temps par quelqu'un d'autre (verrou optimiste) ;
 * - ligne identique créée au même moment (contrainte d'unicité).
 */
final class SafeFlusher
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return bool true si tout est enregistré ; false en cas de conflit (rien n'est enregistré) */
    public function flush(): bool
    {
        try {
            $this->em->flush();

            return true;
        } catch (OptimisticLockException|UniqueConstraintViolationException) {
            return false;
        }
    }
}
