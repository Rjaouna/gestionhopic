<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'counter_type_listed_name', columns: ['name', 'listed'])]
class CounterType
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 100)] public string $name;
    #[ORM\Column(length: 7)] public string $color = '#137b66';
    #[ORM\Column] public bool $active = true;
    #[ORM\Column(nullable: true, options: ['default' => true])] public ?bool $listed = true;
}
