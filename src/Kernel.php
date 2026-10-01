<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Fuseau horaire de l'application, réglable avec APP_TIMEZONE. Il définit le « lundi » d'une semaine,
     * les dates affichées et l'heure des rappels, indépendamment de la configuration du serveur.
     */
    public const DEFAULT_TIMEZONE = 'Africa/Ndjamena';

    public function boot(): void
    {
        if (!$this->booted) {
            $timezone = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? self::DEFAULT_TIMEZONE;
            date_default_timezone_set(\in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : self::DEFAULT_TIMEZONE);
        }

        parent::boot();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
