<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['employee_id', 'counter_type_id'])]
class Balance
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Employee $employee;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public CounterType $counterType;
    #[ORM\Column] public int $units = 0;
}
