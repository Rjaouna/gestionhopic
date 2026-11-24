<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Department
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 100, unique: true)] public string $name;
    #[ORM\Column(length: 255)] public string $description = '';
    #[ORM\Column(length: 7)] public string $color = '#137b66';
    #[ORM\Column] public bool $active = true;
}
