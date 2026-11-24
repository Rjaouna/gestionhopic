<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class BalanceMovement
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Employee $employee;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public CounterType $counterType;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Employee $actor;
    #[ORM\ManyToOne] public ?LeaveRequest $leaveRequest = null;
    #[ORM\Column] public int $oldUnits;
    #[ORM\Column] public int $newUnits;
    #[ORM\Column(length: 30)] public string $kind;
    #[ORM\Column(length: 500)] public string $reason;
    #[ORM\Column(length: 25)] public string $createdAt;
}
