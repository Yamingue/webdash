<?php

namespace App\Tests\Twig;

use App\Twig\Components\Gauge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GaugeTest extends TestCase
{
    private function gauge(?float $value, float $max = 100): Gauge
    {
        $gauge = new Gauge();
        $gauge->value = $value;
        $gauge->max = $max;

        return $gauge;
    }

    /** @return iterable<string, array{?float, float, float}> */
    public static function ratios(): iterable
    {
        yield 'vide' => [0.0, 100, 0.0];
        yield '78 %' => [78.0, 100, 0.78];
        yield 'plein' => [100.0, 100, 1.0];
        yield 'au-delà du max : arc plein' => [123.0, 100, 1.0];
        yield 'négatif : vide' => [-5.0, 100, 0.0];
        yield 'échelle personnalisée' => [60.0, 120, 0.5];
        yield 'sans donnée' => [null, 100, 0.0];
        yield 'échelle dégénérée' => [50.0, 0, 0.0];
    }

    #[DataProvider('ratios')]
    public function testRatio(?float $value, float $max, float $expected): void
    {
        $this->assertSame($expected, $this->gauge($value, $max)->ratio());
    }

    public function testPointsLieOnTheCircle(): void
    {
        $gauge = $this->gauge(50);

        $this->assertSame(['x' => 21.72, 'y' => 78.28], $gauge->point(135), 'départ : en bas à gauche');
        $this->assertSame(['x' => 78.28, 'y' => 78.28], $gauge->point(45), 'fin : en bas à droite');
        $this->assertSame(['x' => 50.0, 'y' => 10.0], $gauge->point(270), 'sommet de l\'arc');
    }

    public function testTrackCoversTheWholeArcFromBottomLeftToBottomRight(): void
    {
        $path = $this->gauge(50)->trackPath();

        $this->assertStringStartsWith('M 21.72 78.28 A 40.00 40.00 0 1 1 ', $path, 'arc de 270° : grand arc, sens horaire');
        $this->assertStringEndsWith(' 78.28 78.28', $path);
    }

    public function testProgressArcFollowsTheValue(): void
    {
        // 50 % de 270° = 135° de plus que le départ → au sommet de l'arc
        $half = $this->gauge(50)->progressPath();
        $this->assertStringStartsWith('M 21.72 78.28 A 40.00 40.00 0 0 1 ', $half, 'moins de 180° : petit arc');
        $this->assertStringEndsWith(' 50.00 10.00', $half);

        // 78 % dépasse 180° : grand arc
        $this->assertStringContainsString(' 0 1 1 ', $this->gauge(78)->progressPath());

        // valeur pleine ou au-delà : identique au fond
        $this->assertSame($this->gauge(100)->trackPath(), $this->gauge(100)->progressPath());
        $this->assertSame($this->gauge(140)->trackPath(), $this->gauge(140)->progressPath());
    }

    public function testNoProgressArcWithoutValue(): void
    {
        $this->assertNull($this->gauge(0)->progressPath());
        $this->assertNull($this->gauge(null)->progressPath());
        $this->assertNotSame('', $this->gauge(null)->trackPath(), 'le fond reste dessiné');
    }
}
