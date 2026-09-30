<?php

namespace App\Entity;

use App\Enum\MembershipRole;
use App\Repository\MembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** Rattache un utilisateur à un domaine avec un rôle propre à ce domaine. */
#[ORM\Entity(repositoryClass: MembershipRepository::class)]
#[ORM\UniqueConstraint(columns: ['user_id', 'domain_id'])]
class Membership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Domain $domain = null;

    #[ORM\Column(length: 20, enumType: MembershipRole::class)]
    #[Assert\NotNull]
    private ?MembershipRole $role = null;

    public function __construct(?User $user = null, ?Domain $domain = null, ?MembershipRole $role = null)
    {
        $this->user = $user;
        $this->domain = $domain;
        $this->role = $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
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

    public function getRole(): ?MembershipRole
    {
        return $this->role;
    }

    public function setRole(?MembershipRole $role): static
    {
        $this->role = $role;

        return $this;
    }
}
