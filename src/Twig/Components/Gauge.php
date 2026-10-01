<?php

namespace App\Twig\Components;

use App\Dashboard\DashboardProvider;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Jauge en arc dessinée en SVG, sans dépendance : un fond gris clair et un arc rempli proportionnellement
 * à la valeur, avec la valeur au centre.
 * - forme « arc » (défaut) : 270°, ouverte en bas, arc indigo ;
 * - forme « half » : demi-cercle ; avec « toned », l'arc et la valeur prennent la couleur du seuil atteint
 *   (rouge, ambre, vert).
 */
#[AsTwigComponent('Dashboard:Gauge', template: 'components/Dashboard/Gauge.html.twig')]
final class Gauge
{
    public const SHAPE_ARC = 'arc';
    public const SHAPE_HALF = 'half';

    private const CX = 50.0;
    private const CY = 50.0;
    private const RADIUS = 40.0;

    public string $label = '';
    /** Si renseigné, le libellé est un lien (ex. vers la page du domaine). */
    public ?string $labelUrl = null;
    /** Si renseigné, un lien « Voir le calcul » apparaît en bas de la jauge. */
    public ?string $detailUrl = null;
    /** Icône UX Icons affichée devant le libellé (forme « arc »). */
    public string $icon = 'lucide:target';
    public string $shape = self::SHAPE_ARC;
    /** Colore l'arc et la valeur selon les seuils du tableau de bord (rouge / ambre / vert). */
    public bool $toned = false;
    public ?float $value = null;
    /** Écart avec la période précédente, dans l'unité de la jauge. */
    public ?float $trend = null;
    public string $trendLabel = 'pts vs sem. préc.';
    /** Valeur pour laquelle l'arc est plein. */
    public float $max = 100.0;
    public string $unit = '%';

    public function isHalf(): bool
    {
        return self::SHAPE_HALF === $this->shape;
    }

    /** Part de l'arc remplie, bornée à [0, 1] (une valeur au-delà du max remplit tout l'arc). */
    public function ratio(): float
    {
        if (null === $this->value || $this->max <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $this->value / $this->max));
    }

    /** Arc de fond, sur toute l'amplitude. */
    public function trackPath(): string
    {
        return $this->arc($this->start(), $this->start() + $this->sweep());
    }

    /** Arc rempli, null s'il n'y a rien à remplir. */
    public function progressPath(): ?string
    {
        $ratio = $this->ratio();

        return $ratio > 0 ? $this->arc($this->start(), $this->start() + $this->sweep() * $ratio) : null;
    }

    /** Point du cercle à l'angle $degrees. @return array{x: float, y: float} */
    public function point(float $degrees): array
    {
        $rad = deg2rad($degrees);

        return [
            'x' => round(self::CX + self::RADIUS * cos($rad), 2),
            'y' => round(self::CY + self::RADIUS * sin($rad), 2),
        ];
    }

    /** Zone visible du SVG (viewBox). */
    public function viewBox(): string
    {
        return $this->isHalf() ? '4 4 92 52' : '4 4 92 84';
    }

    /** Position verticale de la valeur au centre. */
    public function textY(): float
    {
        return $this->isHalf() ? 46.0 : 57.0;
    }

    public function fontSize(): int
    {
        return $this->isHalf() ? 18 : 20;
    }

    /** Couleur du seuil atteint : red | amber | green ; null sans valeur. */
    public function tone(): ?string
    {
        return match (true) {
            null === $this->value => null,
            $this->value < DashboardProvider::THRESHOLD_WARN => 'red',
            $this->value < DashboardProvider::THRESHOLD_GOAL => 'amber',
            default => 'green',
        };
    }

    /** Angle de départ (degrés, sens horaire depuis l'axe x, y vers le bas) : 135° = en bas à gauche, 180° = à gauche. */
    private function start(): float
    {
        return $this->isHalf() ? 180.0 : 135.0;
    }

    private function sweep(): float
    {
        return $this->isHalf() ? 180.0 : 270.0;
    }

    private function arc(float $from, float $to): string
    {
        $a = $this->point($from);
        $b = $this->point($to);
        $large = ($to - $from) > 180 ? 1 : 0;

        return \sprintf('M %.2F %.2F A %.2F %.2F 0 %d 1 %.2F %.2F', $a['x'], $a['y'], self::RADIUS, self::RADIUS, $large, $b['x'], $b['y']);
    }
}
