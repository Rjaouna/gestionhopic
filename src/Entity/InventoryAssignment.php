<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class InventoryAssignment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public InventoryCampaign $campaign;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Employee $employee;
    #[ORM\Column(length: 255, nullable: true)] public ?string $brand = null;
    #[ORM\Column(length: 255, nullable: true)] public ?string $category = null;
    #[ORM\Column] public int $assignedAt;
    #[ORM\Column(nullable: true)] public ?int $closedAt = null;
    #[ORM\Column(nullable: true)] public ?int $reviewSkippedAt = null;
}
