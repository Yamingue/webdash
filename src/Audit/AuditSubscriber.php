<?php

namespace App\Audit;

use App\Entity\AuditLog;
use App\Entity\Evaluation;
use App\Entity\Kpi;
use App\Entity\User;
use App\Enum\EvaluationStatus;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Alimente le journal d'audit à chaque enregistrement en base, sans que les contrôleurs aient à y penser :
 * transitions d'une évaluation, objectif ajusté, modification d'un KPI.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AuditSubscriber
{
    /** Champs d'un KPI suivis => libellé. */
    private const KPI_FIELDS = [
        'defaultTarget' => 'objectif par défaut',
        'lowerIsBetter' => 'sens',
        'weight' => 'poids',
        'active' => 'statut',
        'name' => 'nom',
        'code' => 'code',
        'unit' => 'unité',
    ];

    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $actor = $this->tokenStorage->getToken()?->getUser();
        $actor = $actor instanceof User ? $actor : null;

        $logs = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Evaluation) {
                array_push($logs, ...$this->forNewEvaluation($entity, $actor));
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changes = $uow->getEntityChangeSet($entity);
            if ($entity instanceof Evaluation) {
                array_push($logs, ...$this->forEvaluation($entity, $changes, $actor));
            } elseif ($entity instanceof Kpi) {
                array_push($logs, ...$this->forKpi($entity, $changes, $actor));
            }
        }

        $meta = $em->getClassMetadata(AuditLog::class);
        foreach ($logs as $log) {
            $em->persist($log);
            $uow->computeChangeSet($meta, $log);
        }
    }

    /** @return list<AuditLog> */
    private function forNewEvaluation(Evaluation $evaluation, ?User $actor): array
    {
        $logs = [];
        $default = $evaluation->getKpi()->getDefaultTarget();
        if ($evaluation->getTarget() !== $default) {
            $logs[] = $this->targetChanged($evaluation, $default, $evaluation->getTarget(), $actor);
        }
        if (EvaluationStatus::Submitted === $evaluation->getStatus()) {
            $logs[] = $this->evaluationLog(AuditAction::EvaluationSubmitted, $evaluation, $actor, 'soumise');
        }

        return $logs;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     *
     * @return list<AuditLog>
     */
    private function forEvaluation(Evaluation $evaluation, array $changes, ?User $actor): array
    {
        $logs = [];

        if (isset($changes['target']) && (float) $changes['target'][0] !== (float) $changes['target'][1]) {
            $logs[] = $this->targetChanged($evaluation, (float) $changes['target'][0], (float) $changes['target'][1], $actor);
        }

        if (isset($changes['status'])) {
            // Selon le chemin, Doctrine fournit les valeurs brutes (chaînes) ou des enums : on normalise.
            [$from, $to] = array_map(self::toStatus(...), $changes['status']);
            $reason = $evaluation->getRejectionReason();
            $logs[] = match (true) {
                EvaluationStatus::Draft === $from && EvaluationStatus::Submitted === $to => $this->evaluationLog(AuditAction::EvaluationSubmitted, $evaluation, $actor, 'soumise'),
                EvaluationStatus::Submitted === $from && EvaluationStatus::Validated === $to => $this->evaluationLog(AuditAction::EvaluationValidated, $evaluation, $actor, 'validée'),
                EvaluationStatus::Submitted === $from && EvaluationStatus::Draft === $to => $this->evaluationLog(AuditAction::EvaluationRejected, $evaluation, $actor, 'rejetée', ['reason' => $reason]),
                EvaluationStatus::Validated === $from && EvaluationStatus::Draft === $to => $this->evaluationLog(AuditAction::EvaluationReopened, $evaluation, $actor, 'déverrouillée', ['reason' => $reason]),
                default => null,
            };
        }

        return array_values(array_filter($logs));
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changes
     *
     * @return list<AuditLog>
     */
    private function forKpi(Kpi $kpi, array $changes, ?User $actor): array
    {
        $diff = $parts = [];
        foreach (self::KPI_FIELDS as $field => $label) {
            if (!isset($changes[$field]) || $changes[$field][0] === $changes[$field][1]) {
                continue;
            }
            [$old, $new] = $changes[$field];
            $diff[$field] = ['old' => $old, 'new' => $new];
            $parts[] = \sprintf('%s %s → %s', $label, self::format($field, $old), self::format($field, $new));
        }
        if ([] === $diff) {
            return [];
        }

        return [new AuditLog(
            AuditAction::KpiUpdated,
            \sprintf('%s : %s', $kpi->getCode(), implode(' ; ', $parts)),
            $actor,
            $kpi->getDomain(),
            'kpi',
            $kpi->getId(),
            ['kpi' => $kpi->getCode(), 'changes' => $diff],
        )];
    }

    private function targetChanged(Evaluation $evaluation, float $old, float $new, ?User $actor): AuditLog
    {
        return $this->evaluationLog(
            AuditAction::EvaluationTargetChanged,
            $evaluation,
            $actor,
            \sprintf('objectif ajusté de %s à %s', self::number($old), self::number($new)),
            ['old' => $old, 'new' => $new],
        );
    }

    /** @param array<string, mixed> $extra */
    private function evaluationLog(AuditAction $action, Evaluation $evaluation, ?User $actor, string $verb, array $extra = []): AuditLog
    {
        $kpi = $evaluation->getKpi();
        $summary = \sprintf('%s, semaine du %s : %s', $kpi->getCode(), $evaluation->getWeekStart()->format('d/m/Y'), $verb);
        if (!empty($extra['reason'])) {
            $summary .= \sprintf(' (motif : %s)', $extra['reason']);
        }

        return new AuditLog(
            $action,
            $summary,
            $actor,
            $kpi->getDomain(),
            'evaluation',
            $evaluation->getId(),
            ['kpi' => $kpi->getCode(), 'week' => $evaluation->getWeekStart()->format('Y-m-d')] + $extra,
        );
    }

    private static function format(string $field, mixed $value): string
    {
        return match ($field) {
            'lowerIsBetter' => $value ? 'plus bas = mieux' : 'plus haut = mieux',
            'active' => $value ? 'actif' : 'inactif',
            'defaultTarget', 'weight' => self::number((float) $value),
            default => null === $value || '' === $value ? '—' : (string) $value,
        };
    }

    private static function toStatus(mixed $value): ?EvaluationStatus
    {
        return $value instanceof EvaluationStatus ? $value : (null === $value ? null : EvaluationStatus::tryFrom((string) $value));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
