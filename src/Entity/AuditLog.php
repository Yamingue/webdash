<?php

namespace App\Entity;

use App\Audit\AuditAction;
use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Une ligne du journal d'audit : qui a fait quoi, sur quel domaine, avec le détail avant/après. */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Conservé même si le compte est supprimé (le nom reste dans $actorName). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(length: 120)]
    private string $actorName;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Domain $domain;

    #[ORM\Column(length: 40, enumType: AuditAction::class)]
    private AuditAction $action;

    #[ORM\Column(length: 40)]
    private string $entityType;

    #[ORM\Column(nullable: true)]
    private ?int $entityId;

    #[ORM\Column(type: Types::TEXT)]
    private string $summary;

    /** @var array<string, mixed> */
    #[ORM\Column]
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(
        AuditAction $action,
        string $summary,
        ?User $actor,
        ?Domain $domain,
        string $entityType,
        ?int $entityId,
        array $data = [],
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->action = $action;
        $this->summary = $summary;
        $this->actor = $actor;
        $this->actorName = $actor?->getFullName() ?: 'Système';
        $this->domain = $domain;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->data = $data;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorName(): string
    {
        return $this->actorName;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function getAction(): AuditAction
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }
}
