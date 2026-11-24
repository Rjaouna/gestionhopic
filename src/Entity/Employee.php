<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'employee')]
class Employee implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 100)] public string $firstName;
    #[ORM\Column(length: 100)] public string $lastName;
    #[ORM\Column(length: 180, unique: true)] public string $email;
    #[ORM\Column(length: 30, unique: true)] public string $code;
    #[ORM\Column(length: 120)] public string $job = '';
    #[ORM\Column(length: 40)] public string $phone = '';
    #[ORM\Column(length: 255)] public string $password;
    #[ORM\Column(length: 20)] public string $role = 'ROLE_USER';
    #[ORM\Column] public bool $active = true;
    #[ORM\Column] public bool $archived = false;
    #[ORM\Column(length: 25, nullable: true)] public ?string $deletedAt = null;
    #[ORM\ManyToOne] public ?Department $department = null;
    #[ORM\ManyToOne] public ?WorkSchedule $schedule = null;
    #[ORM\Column(length: 10, nullable: true)] public ?string $hiredOn = null;
    #[ORM\Column(length: 10, nullable: true)] public ?string $leftOn = null;
    #[ORM\Column(length: 10, nullable: true)] public ?string $paidLeaveThrough = null;
    #[ORM\Column(length: 25)] public string $createdAt;

    public function getUserIdentifier(): string { return $this->email; }
    public function getPassword(): string { return $this->password; }
    public function getRoles(): array { return [$this->role]; }
    public function getDisplayName(): string { return trim($this->firstName.' '.$this->lastName); }
    public function getInitials(): string { return mb_strtoupper(mb_substr($this->firstName, 0, 1).mb_substr($this->lastName, 0, 1)); }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && $this->id === $user->id
            && $this->email === $user->email
            && $this->password === $user->password
            && $this->role === $user->role
            && $this->active === $user->active
            && $this->archived === $user->archived
            && $this->deletedAt === $user->deletedAt;
    }
}
