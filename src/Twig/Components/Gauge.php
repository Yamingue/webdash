<?php

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Jauge en arc (270°, ouverte en bas) : un fond gris clair et un arc indigo rempli
 * proportionnellement à la valeur, avec la valeur au centre. Dessinée en SVG, sans dépendance.
 */
#[AsTwigComponent('Dashboard:Gauge', template: 'components/Dashboard/Gauge.html.twig')]
final class Gauge
{
    private const CX = 50.0;
    private const CY = 50.0;
    private const RADIUS = 40.0;
    /** Angle de départ (degrés, sens horaire depuis l'axe x, y vers le bas) : 135° = en bas à gauche. */
    private const START = 135.0;
    /** Amplitude totale de l'arc. */
    private const SWEEP = 270.0;

    public string $label = '';
    /** Icône UX Icons affichée devant le libellé. */
    public string $icon = 'lucide:target';
    public ?float $value = null;
    /** Écart avec la période précédente, dans l'unité de la jauge. */
    public ?float $trend = null;
    public string $trendLabel = 'pts vs sem. préc.';
    /** Valeur pour laquelle l'arc est plein. */
    public float $max = 100.0;
    public string $unit = '%';

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
        return $this->arc(self::START, self::START + self::SWEEP);
    }

    /** Arc rempli, null s'il n'y a rien à remplir. */
    public function progressPath(): ?string
    {
        $ratio = $this->ratio();

        return $ratio > 0 ? $this->arc(self::START, self::START + self::SWEEP * $ratio) : null;
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

    private function arc(float $from, float $to): string
    {
        $a = $this->point($from);
        $b = $this->point($to);
        $large = ($to - $from) > 180 ? 1 : 0;

        return \sprintf('M %.2F %.2F A %.2F %.2F 0 %d 1 %.2F %.2F', $a['x'], $a['y'], self::RADIUS, self::RADIUS, $large, $b['x'], $b['y']);
    }
}
