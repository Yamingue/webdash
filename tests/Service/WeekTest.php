<?php

namespace App\Tests\Service;

use App\Service\Week;
use PHPUnit\Framework\TestCase;

final class WeekTest extends TestCase
{
    public function testMondayOf(): void
    {
        $this->assertSame('2026-09-28', Week::mondayOf(new \DateTimeImmutable('2026-09-30 15:00'))->format('Y-m-d'));
        $this->assertSame('2026-09-28', Week::mondayOf(new \DateTimeImmutable('2026-10-04'))->format('Y-m-d'), 'dimanche → lundi précédent');
    }

    public function testResolve(): void
    {
        $now = new \DateTimeImmutable('2026-09-30');
        $this->assertSame('2026-09-21', Week::resolve('2026-09-24', $now)->format('Y-m-d'));
        $this->assertSame('2029-12-31', Week::resolve('2030-01-01', $now)->format('Y-m-d'), 'semaine future autorisée');
        $this->assertSame('2026-09-28', Week::resolve('n\'importe quoi', $now)->format('Y-m-d'));
        $this->assertSame('2026-09-28', Week::resolve(null, $now)->format('Y-m-d'));
    }

    public function testLastWeeks(): void
    {
        $weeks = Week::lastWeeks(3, new \DateTimeImmutable('2026-09-30'));
        $this->assertSame(['2026-09-14', '2026-09-21', '2026-09-28'], array_map(static fn ($w) => $w->format('Y-m-d'), $weeks));
    }
}
