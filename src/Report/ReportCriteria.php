<?php

namespace App\Report;

use App\Entity\Domain;
use App\Enum\EvaluationStatus;

/** Critères résolus et bornés d'un rapport : lundis de début et de fin inclus, domaine éventuel, statuts. */
final readonly class ReportCriteria
{
    /** Soumises + validées : les mêmes chiffres que le tableau de bord. */
    public const STATUS_REPORTABLE = 'reportable';
    public const STATUS_VALIDATED = 'validated';
    /** Brouillons compris. */
    public const STATUS_ALL = 'all';
    public const STATUSES = [self::STATUS_REPORTABLE, self::STATUS_VALIDATED, self::STATUS_ALL];

    /** Durée maximale d'un rapport, en semaines. */
    public const MAX_WEEKS = 104;

    public function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public ?Domain $domain,
        public string $status,
        /** Vrai si la période demandée dépassait MAX_WEEKS et a été raccourcie. */
        public bool $clamped = false,
    ) {
    }

    /** @return list<EvaluationStatus> */
    public function statuses(): array
    {
        return match ($this->status) {
            self::STATUS_VALIDATED => [EvaluationStatus::Validated],
            self::STATUS_ALL => [EvaluationStatus::Draft, EvaluationStatus::Submitted, EvaluationStatus::Validated],
            default => [EvaluationStatus::Submitted, EvaluationStatus::Validated],
        };
    }

    /** Paramètres de requête pour reproduire ce rapport (liens d'export). */
    public function queryParams(): array
    {
        return array_filter([
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'domain' => $this->domain?->getId(),
            'status' => $this->status,
        ], static fn (mixed $v): bool => null !== $v);
    }

    public function weekCount(): int
    {
        return (int) ($this->from->diff($this->to)->days / 7) + 1;
    }
}
