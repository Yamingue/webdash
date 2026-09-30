<?php

namespace App\Entity;

use App\Enum\MembershipRole;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity('email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email = '';

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $fullName = '';

    /**
     * Rôles globaux uniquement : ROLE_ADMIN, ROLE_DIRECTOR.
     * Les droits par domaine passent par Membership.
     *
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /** Recevoir les rappels hebdomadaires par e-mail. */
    #[ORM\Column(options: ['default' => true])]
    private bool $notifyByEmail = true;

    #[ORM\Column]
    private string $password = '';

    /** @var Collection<int, Membership> */
    #[ORM\OneToMany(targetEntity: Membership::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Assert\Valid]
    private Collection $memberships;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = (string) $email;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): static
    {
        $this->fullName = (string) $fullName;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function isNotifyByEmail(): bool
    {
        return $this->notifyByEmail;
    }

    public function setNotifyByEmail(bool $notifyByEmail): static
    {
        $this->notifyByEmail = $notifyByEmail;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /** @return Collection<int, Membership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(Membership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setUser($this);
        }

        return $this;
    }

    public function removeMembership(Membership $membership): static
    {
        $this->memberships->removeElement($membership);

        return $this;
    }

    public function isAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    /** Admin et directeur voient toutes les domaines. */
    public function hasGlobalAccess(): bool
    {
        return $this->isAdmin() || \in_array('ROLE_DIRECTOR', $this->roles, true);
    }

    public function getRoleIn(Domain $domain): ?MembershipRole
    {
        foreach ($this->memberships as $membership) {
            if (self::sameDomain($membership->getDomain(), $domain)) {
                return $membership->getRole();
            }
        }

        return null;
    }

    #[Assert\Callback]
    public function validateMemberships(ExecutionContextInterface $context): void
    {
        $seen = [];
        foreach ($this->memberships as $membership) {
            $id = $membership->getDomain()?->getId();
            if (null === $id) {
                continue;
            }
            if (isset($seen[$id])) {
                $context->buildViolation('Une seule appartenance par domaine.')->atPath('memberships')->addViolation();

                return;
            }
            $seen[$id] = true;
        }
    }

    private static function sameDomain(?Domain $a, Domain $b): bool
    {
        return $a === $b || (null !== $a && null !== $a->getId() && $a->getId() === $b->getId());
    }
}
