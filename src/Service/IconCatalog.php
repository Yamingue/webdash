<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Icônes proposées pour les catégories : toutes celles importées localement dans assets/icons.
 * Pour en ajouter : bin/console ux:icons:import lucide:<nom>.
 */
final class IconCatalog
{
    public function __construct(#[Autowire('%kernel.project_dir%/assets/icons')] private readonly string $iconDir)
    {
    }

    /** @return array<string, string> nom UX Icons => même nom, trié */
    public function choices(): array
    {
        $choices = [];
        foreach (glob($this->iconDir.'/*/*.svg') ?: [] as $file) {
            $name = basename(\dirname($file)).':'.basename($file, '.svg');
            $choices[$name] = $name;
        }
        ksort($choices);

        return $choices;
    }
}
