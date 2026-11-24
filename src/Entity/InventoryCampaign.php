<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class InventoryCampaign
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 120)] public string $name;
    #[ORM\Column] public int $startedAt;
    #[ORM\Column(nullable: true)] public ?int $closedAt = null;
}
