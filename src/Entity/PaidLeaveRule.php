<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['counter_type_id'], name: 'IDX_PAID_LEAVE_RULE_COUNTER')]
class PaidLeaveRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne] public ?CounterType $counterType = null;
    #[ORM\Column] public int $monthlyUnits = 3;
    #[ORM\Column] public int $waitingMonths = 6;
    #[ORM\Column] public bool $automatic = true;
    #[ORM\Column(length: 10, nullable: true)] public ?string $lastRunOn = null;
}
