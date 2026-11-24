<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class EbpConnection
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(type: 'text')] public string $sourceUrl;
    #[ORM\Column(type: 'text')] public string $photoPath;
    #[ORM\Column(length: 16)] public string $state;
    #[ORM\Column] public int $attempts;
    #[ORM\Column] public int $nextAttemptAt;
    #[ORM\Column(length: 32, nullable: true)] public ?string $lease = null;
    #[ORM\Column] public int $leasedUntil;
    #[ORM\Column(length: 255)] public string $message;
    #[ORM\Column(nullable: true)] public ?int $lastImportAt = null;
    #[ORM\Column] public int $lastCount;
}
