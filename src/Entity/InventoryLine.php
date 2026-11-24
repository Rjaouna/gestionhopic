<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'inventory_line_campaign_article', columns: ['campaign_id', 'unique_id'])]
class InventoryLine
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public InventoryCampaign $campaign;
    #[ORM\ManyToOne] public ?InventoryAssignment $assignment = null;
    #[ORM\Column(length: 100)] public string $uniqueId;
    #[ORM\Column(length: 255)] public string $reference;
    #[ORM\Column(type: 'text')] public string $description;
    #[ORM\Column(length: 255)] public string $brand;
    #[ORM\Column(length: 255)] public string $category;
    #[ORM\Column(length: 255)] public string $webCode;
    #[ORM\Column(type: 'text')] public string $searchText;
    #[ORM\Column(length: 32)] public string $expectedQuantity;
    #[ORM\Column(length: 32, nullable: true)] public ?string $countedQuantity = null;
    #[ORM\Column] public bool $completed = false;
    #[ORM\Column] public int $version = 0;
}
