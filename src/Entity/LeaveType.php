<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class LeaveType
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 100, unique: true)] public string $name;
    #[ORM\Column(length: 16)] public string $tone = 'mint';
    #[ORM\Column(length: 40)] public string $icon = 'calendar3';
    #[ORM\Column(length: 255)] public string $description = '';
    #[ORM\Column] public bool $active = true;
    #[ORM\Column] public bool $requiresApproval = true;
    #[ORM\Column(options: ['default' => true])] public bool $allowAttachment = true;
    #[ORM\Column] public bool $requiresAttachment = false;
    #[ORM\Column] public bool $allowHalfDays = true;
    #[ORM\Column] public bool $employeeVisible = true;
    #[ORM\Column] public bool $privateReason = false;
    #[ORM\ManyToOne] public ?CounterType $counterType = null;
}
