<?php

namespace App\Tests\Dashboard;

use App\Dashboard\KpiStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KpiStatusTest extends TestCase
{
    /** @return iterable<string, array{?float, KpiStatus}> */
    public static function rates(): iterable
    {
        yield 'sans taux' => [null, KpiStatus::Missing];
        yield '0 %' => [0.0, KpiStatus::Red];
        yield '50 %' => [50.0, KpiStatus::Red];
        yield 'juste sous le seuil rouge' => [79.9, KpiStatus::Red];
        yield 'seuil rouge/ambre : 80 % est ambre' => [80.0, KpiStatus::Amber];
        yield 'ambre' => [95.0, KpiStatus::Amber];
        yield 'juste sous l\'objectif' => [99.9, KpiStatus::Amber];
        yield 'objectif atteint : 100 % est vert' => [100.0, KpiStatus::Green];
        yield 'dépassé' => [143.0, KpiStatus::Green];
        yield 'plus bas = mieux, score 0 (200 %)' => [200.0, KpiStatus::Green];
    }

    #[DataProvider('rates')]
    public function testFromRate(?float $rate, KpiStatus $expected): void
    {
        $this->assertSame($expected, KpiStatus::fromRate($rate));
    }

    public function testEveryStatusHasWordingAndABadge(): void
    {
        $expected = [
            'red' => ['KPI rouges', 'Action urgente', 'Rouge', 'danger'],
            'amber' => ['KPI ambre', 'Surveillance', 'Ambre', 'warning'],
            'green' => ['KPI verts', 'Objectif atteint', 'Verts', 'success'],
            'missing' => ['Non renseignés', 'Données manquantes', 'Non renseigné', 'neutral'],
        ];

        foreach (KpiStatus::cases() as $status) {
            [$label, $subtitle, , $badge] = $expected[$status->value];
            $this->assertSame($label, $status->label());
            $this->assertSame($subtitle, $status->subtitle());
            $this->assertSame($badge, $status->badgeVariant());
        }
        $this->assertSame(['red', 'amber', 'green', 'missing'], array_map(static fn (KpiStatus $s) => $s->value, KpiStatus::cases()), 'ordre des cartes');
    }

    public function testUrgencyOrder(): void
    {
        $byPriority = KpiStatus::cases();
        usort($byPriority, static fn (KpiStatus $a, KpiStatus $b): int => $a->priority() <=> $b->priority());

        $this->assertSame(['red', 'amber', 'missing', 'green'], array_map(static fn (KpiStatus $s) => $s->value, $byPriority));
    }
}
