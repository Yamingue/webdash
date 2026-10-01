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

    private function half(?float $value): Gauge
    {
        $gauge = $this->gauge($value);
        $gauge->shape = Gauge::SHAPE_HALF;

        return $gauge;
    }

    public function testHalfShapeIsASemicircleFromLeftToRight(): void
    {
        $gauge = $this->half(50);

        $this->assertTrue($gauge->isHalf());
        $this->assertSame('M 10.00 50.00 A 40.00 40.00 0 0 1 90.00 50.00', $gauge->trackPath(), 'de gauche à droite, petit arc');
        $this->assertSame('M 10.00 50.00 A 40.00 40.00 0 0 1 50.00 10.00', $gauge->progressPath(), '50 % = jusqu\'au sommet');
        $this->assertSame($gauge->trackPath(), $this->half(100)->progressPath(), 'valeur pleine = arc complet');
        $this->assertSame($gauge->trackPath(), $this->half(180)->progressPath(), 'au-delà du max aussi');
        $this->assertNull($this->half(0)->progressPath());
        $this->assertNull($this->half(null)->progressPath());
    }

    public function testShapesHaveTheirOwnFrameAndTextPosition(): void
    {
        $arc = $this->gauge(10);
        $half = $this->half(10);

        $this->assertFalse($arc->isHalf());
        $this->assertSame('4 4 92 84', $arc->viewBox());
        $this->assertSame('4 4 92 52', $half->viewBox());
        $this->assertSame(57.0, $arc->textY());
        $this->assertSame(46.0, $half->textY());
        $this->assertGreaterThan($half->fontSize() - 1, $arc->fontSize());
    }

    /** @return iterable<string, array{?float, ?string}> */
    public static function tones(): iterable
    {
        yield 'sans valeur' => [null, null];
        yield '0 %' => [0.0, 'red'];
        yield 'juste sous 80 %' => [79.9, 'red'];
        yield '80 % : ambre' => [80.0, 'amber'];
        yield 'juste sous 100 %' => [99.9, 'amber'];
        yield '100 % : vert' => [100.0, 'green'];
        yield 'au-delà' => [130.0, 'green'];
    }

    #[DataProvider('tones')]
    public function testToneFollowsTheDashboardThresholds(?float $value, ?string $expected): void
    {
        $this->assertSame($expected, $this->gauge($value)->tone());
    }

    public function testNoProgressArcWithoutValue(): void
    {
        $this->assertNull($this->gauge(0)->progressPath());
        $this->assertNull($this->gauge(null)->progressPath());
        $this->assertNotSame('', $this->gauge(null)->trackPath(), 'le fond reste dessiné');
    }
}
