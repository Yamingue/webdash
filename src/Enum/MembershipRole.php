<?php

namespace App\Enum;

enum MembershipRole: string
{
    case Manager = 'manager';
    case Evaluator = 'evaluator';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Responsable',
            self::Evaluator => 'Évaluateur',
            self::Viewer => 'Lecteur',
        };
    }
}
