<?php

namespace App\Audit;

/** Types d'événements du journal d'audit. */
enum AuditAction: string
{
    case EvaluationSubmitted = 'evaluation.submitted';
    case EvaluationValidated = 'evaluation.validated';
    case EvaluationRejected = 'evaluation.rejected';
    case EvaluationReopened = 'evaluation.reopened';
    case EvaluationTargetChanged = 'evaluation.target_changed';
    case KpiUpdated = 'kpi.updated';

    public function label(): string
    {
        return match ($this) {
            self::EvaluationSubmitted => 'Soumission',
            self::EvaluationValidated => 'Validation',
            self::EvaluationRejected => 'Rejet',
            self::EvaluationReopened => 'Déverrouillage',
            self::EvaluationTargetChanged => 'Objectif ajusté',
            self::KpiUpdated => 'KPI modifié',
        };
    }

    /** Variante de pastille (composant Badge). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::EvaluationValidated => 'success',
            self::EvaluationRejected, self::EvaluationReopened => 'danger',
            self::EvaluationSubmitted, self::EvaluationTargetChanged => 'warning',
            self::KpiUpdated => 'neutral',
        };
    }
}
