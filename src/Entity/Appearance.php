<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'app_appearance')]
class Appearance
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 48)] public string $name = 'tempo';
    #[ORM\Column(length: 12)] public string $suffix = 'RH';
    #[ORM\Column(length: 16)] public string $theme = 'tempo';
    #[ORM\Column(length: 64, nullable: true)] public ?string $logoVersion = null;
    #[ORM\Column(length: 20, nullable: true)] public ?string $logoMime = null;
    #[ORM\Column(type: Types::BLOB, nullable: true)] public mixed $logoContent = null;
}
