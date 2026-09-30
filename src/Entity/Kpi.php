<?php

namespace App\Entity;

use App\Repository\KpiRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: KpiRepository::class)]
#[UniqueEntity('code', message: 'Ce code est déjà utilisé.')]
class Kpi
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'kpis')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Domain $domain = null;

    #[ORM\Column(length: 40, unique: true)]
    #[Assert\NotBlank]
    private string $code = '';

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Objectif par défaut, copié dans chaque nouvelle évaluation. */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private float $defaultTarget = 0;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $active = true;

    /** Plus le score est bas, meilleur il est (churn, taux de coupure…). Copié dans chaque nouvelle évaluation. */
    #[ORM\Column(options: ['default' => false])]
    private bool $lowerIsBetter = false;

    /** Importance du KPI dans la moyenne pondérée de son domaine. Copiée dans chaque nouvelle évaluation. */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive(message: 'Le poids doit être supérieur à 0.')]
    private float $weight = 1.0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(?Domain $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = (string) $code;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = (string) $name;

        return $this;
    }

    public function getDefaultTarget(): float
    {
        return $this->defaultTarget;
    }

    public function setDefaultTarget(?float $defaultTarget): static
    {
        $this->defaultTarget = (float) $defaultTarget; // valeur vide refusée en amont par le formulaire (NotBlank)

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function isLowerIsBetter(): bool
    {
        return $this->lowerIsBetter;
    }

    public function setLowerIsBetter(bool $lowerIsBetter): static
    {
        $this->lowerIsBetter = $lowerIsBetter;

        return $this;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(?float $weight): static
    {
        $this->weight = (float) $weight; // valeur vide refusée en amont par le formulaire (NotBlank)

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }
}
