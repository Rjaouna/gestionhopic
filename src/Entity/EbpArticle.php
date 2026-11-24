<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'ebp_article_unique', columns: ['unique_id'])]
#[ORM\Index(name: 'ebp_article_reference', columns: ['present', 'reference'])]
#[ORM\Index(name: 'ebp_article_brand', columns: ['present', 'brand'])]
#[ORM\Index(name: 'ebp_article_category', columns: ['present', 'category'])]
class EbpArticle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 100)] public string $uniqueId;
    #[ORM\Column(length: 255)] public string $reference;
    #[ORM\Column(type: 'text')] public string $description;
    #[ORM\Column(length: 32)] public string $stock;
    #[ORM\Column(length: 32, options: ['default' => '0'])] public string $countedQuantity = '0';
    #[ORM\Column(length: 255)] public string $brand;
    #[ORM\Column(length: 255)] public string $category;
    #[ORM\Column(length: 255)] public string $webCode;
    #[ORM\Column(type: 'text')] public string $searchText;
    #[ORM\Column(type: 'text')] public string $sourceFields;
    #[ORM\Column] public bool $present = true;
}
