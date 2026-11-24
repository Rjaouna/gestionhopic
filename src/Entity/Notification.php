<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Notification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Employee $employee;
    #[ORM\Column(length: 220)] public string $message;
    #[ORM\Column(length: 25)] public string $createdAt;
    #[ORM\Column(length: 25, nullable: true)] public ?string $readAt = null;
}
