<?php

namespace App\Audit;

/** Filtres du journal (query string). */
final readonly class AuditQuery
{
    public function __construct(
        /** Identifiant d'un domaine ; vide = tous ceux auxquels l'utilisateur a accès. */
        public ?string $domain = null,
        /** Valeur d'AuditAction ; vide ou inconnue = tous les types. */
        public ?string $action = null,
    ) {
    }
}
