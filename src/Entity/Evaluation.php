<?php

namespace App\Entity;

use App\Enum\EvaluationStatus;
use App\Repository\EvaluationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvaluationRepository::class)]
#[ORM\UniqueConstraint(columns: ['kpi_id', 'week_start'])]
#[ORM\Index(columns: ['week_start'])]
#[ORM\Index(columns: ['status'])]
class Evaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Kpi $kpi;

    /** Lundi de la semaine évaluée. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weekStart;

    /** Objectif figé à la création : les changements ultérieurs de Kpi::$defaultTarget ne l'affectent pas. */
    #[ORM\Column]
    private float $target;

    /** Sens du KPI figé à la création : true = plus le score est bas, meilleur il est. */
    #[ORM\Column(options: ['default' => false])]
    private bool $lowerIsBetter = false;

    /** Poids du KPI figé à la création : pondère la moyenne du domaine sans réécrire l'historique. */
    #[ORM\Column(options: ['default' => 1])]
    private float $weight = 1.0;

    #[ORM\Column(nullable: true)]
    private ?float $score = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 20, enumType: EvaluationStatus::class)]
    private EvaluationStatus $status = EvaluationStatus::Draft;

    #[ORM\ManyToOne]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\ManyToOne]
    private ?User $validatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    /** Verrou optimiste : Doctrine refuse l'enregistrement si la ligne a été modifiée entre-temps par quelqu'un d'autre. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    public function __construct(Kpi $kpi, \DateTimeImmutable $weekStart)
    {
        $this->kpi = $kpi;
        $this->weekStart = $weekStart->modify('monday this week')->setTime(0, 0);
        $this->target = $kpi->getDefaultTarget();
        $this->lowerIsBetter = $kpi->isLowerIsBetter();
        $this->weight = $kpi->getWeight();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isLowerIsBetter(): bool
    {
        return $this->lowerIsBetter;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Version de la ligne ; 0 tant qu'elle n'est pas enregistrée (sert à détecter une modification concurrente). */
    public function getVersion(): int
    {
        return null === $this->id ? 0 : $this->version;
    }

    public function getKpi(): Kpi
    {
        return $this->kpi;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getTarget(): float
    {
        return $this->target;
    }

    public function setTarget(?float $target): static
    {
        $this->target = (float) $target; // valeur vide refusée en amont par le formulaire (NotBlank)

        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getStatus(): EvaluationStatus
    {
        return $this->status;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    /**
     * Taux d'atteinte en pourcentage, null si pas de score (ou pas d'objectif exploitable).
     *
     * - Plus haut = mieux : score ÷ objectif × 100.
     * - Plus bas = mieux : 200 − score ÷ objectif × 100, plancher à 0 (à l'objectif : 100 %,
     *   10 % au-dessus : 90 %, 10 % en dessous : 110 %, le double : 0 %).
     * - Objectif à 0 : sans objet si plus haut = mieux ; sinon 100 % à 0, 0 % au-delà.
     */
    public function getAchievement(): ?float
    {
        if (null === $this->score) {
            return null;
        }

        if ($this->target <= 0) {
            return $this->lowerIsBetter ? ($this->score <= 0 ? 100.0 : 0.0) : null;
        }

        $ratio = $this->score / $this->target * 100;

        return round($this->lowerIsBetter ? max(0.0, 200 - $ratio) : $ratio, 1);
    }

    /** Seules les évaluations en brouillon sont modifiables ; soumise ou validée, elle est verrouillée. */
    public function isEditable(): bool
    {
        return EvaluationStatus::Draft === $this->status;
    }

    /** Brouillon -> soumise (nécessite un score). */
    public function submit(): static
    {
        $this->assertStatus(EvaluationStatus::Draft, 'soumise');
        if (null === $this->score) {
            throw new \LogicException('Une évaluation sans score ne peut pas être soumise.');
        }
        $this->status = EvaluationStatus::Submitted;
        $this->submittedAt = new \DateTimeImmutable();
        $this->rejectionReason = null;

        return $this;
    }

    /** Soumise -> validée (verrouillée définitivement). */
    public function validate(User $by): static
    {
        $this->assertStatus(EvaluationStatus::Submitted, 'validée');
        $this->status = EvaluationStatus::Validated;
        $this->validatedBy = $by;
        $this->validatedAt = new \DateTimeImmutable();

        return $this;
    }

    /** Soumise -> brouillon, avec le motif visible par l'évaluateur. */
    public function reject(string $reason): static
    {
        $this->assertStatus(EvaluationStatus::Submitted, 'rejetée');
        $this->status = EvaluationStatus::Draft;
        $this->rejectionReason = $reason;

        return $this;
    }

    /** Validée -> brouillon : déverrouillage motivé, l'évaluateur peut corriger puis resoumettre. */
    public function reopen(string $reason): static
    {
        $this->assertStatus(EvaluationStatus::Validated, 'rouverte');
        $this->status = EvaluationStatus::Draft;
        $this->rejectionReason = $reason;
        $this->submittedAt = null;
        $this->validatedBy = null;
        $this->validatedAt = null;

        return $this;
    }

    private function assertStatus(EvaluationStatus $expected, string $action): void
    {
        if ($this->status !== $expected) {
            throw new \LogicException(\sprintf('Une évaluation au statut "%s" ne peut pas être %s.', $this->status->value, $action));
        }
    }
}
