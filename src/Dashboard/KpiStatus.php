<?php

namespace App\Dashboard;

/**
 * Statut d'un KPI pour une semaine, d'après son taux d'atteinte et les seuils du tableau de bord :
 * rouge sous THRESHOLD_WARN, ambre jusqu'à THRESHOLD_GOAL (exclu), vert à partir de THRESHOLD_GOAL.
 * « Non renseigné » : pas d'évaluation soumise ou validée cette semaine, ou taux non calculable.
 */
enum KpiStatus: string
{
    case Red = 'red';
    case Amber = 'amber';
    case Green = 'green';
    case Missing = 'missing';

    public static function fromRate(?float $rate): self
    {
        return match (true) {
            null === $rate => self::Missing,
            $rate < DashboardProvider::THRESHOLD_WARN => self::Red,
            $rate < DashboardProvider::THRESHOLD_GOAL => self::Amber,
            default => self::Green,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Red => 'KPI rouges',
            self::Amber => 'KPI ambre',
            self::Green => 'KPI verts',
            self::Missing => 'Non renseignés',
        };
    }

    /** Libellé des pastilles de filtre. */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::Red => 'Rouges',
            self::Amber => 'Ambre',
            self::Green => 'Verts',
            self::Missing => 'Non renseignés',
        };
    }

    /** Libellé court pour une ligne de tableau. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Red => 'Rouge',
            self::Amber => 'Ambre',
            self::Green => 'Vert',
            self::Missing => 'Non renseigné',
        };
    }

    public function subtitle(): string
    {
        return match ($this) {
            self::Red => 'Action urgente',
            self::Amber => 'Surveillance',
            self::Green => 'Objectif atteint',
            self::Missing => 'Données manquantes',
        };
    }

    /** Variante de pastille (composant Badge). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Red => 'danger',
            self::Amber => 'warning',
            self::Green => 'success',
            self::Missing => 'neutral',
        };
    }

    /** Ordre d'urgence pour le tri : rouge, ambre, non renseigné, vert. */
    public function priority(): int
    {
        return match ($this) {
            self::Red => 0,
            self::Amber => 1,
            self::Missing => 2,
            self::Green => 3,
        };
    }
}
