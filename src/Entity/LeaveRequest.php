<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['employee_id', 'status', 'start_on', 'end_on'], name: 'leave_employee_period')]
#[ORM\Index(columns: ['status', 'start_on', 'end_on'], name: 'leave_status_period')]
class LeaveRequest
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public Employee $employee;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)] public LeaveType $leaveType;
    #[ORM\ManyToOne] public ?CounterType $counterType = null;
    #[ORM\Column(length: 10)] public string $startOn;
    #[ORM\Column(length: 10)] public string $endOn;
    #[ORM\Column] public int $startSlot = 0;
    #[ORM\Column] public int $endSlot = 1;
    #[ORM\Column] public int $units;
    #[ORM\Column(options: ['default' => 31])] public int $workdays = 31;
    #[ORM\Column(length: 20)] public string $status = 'pending';
    #[ORM\Column(type: 'text')] public string $comment = '';
    #[ORM\Column(type: 'text')] public string $decisionNote = '';
    #[ORM\Column(length: 25)] public string $createdAt;
    #[ORM\Column(length: 25, nullable: true)] public ?string $decidedAt = null;
    #[ORM\ManyToOne] public ?Employee $decidedBy = null;
    #[ORM\Column(length: 70, nullable: true)] public ?string $attachment = null;
    #[ORM\Column(length: 255, nullable: true)] public ?string $attachmentName = null;

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): void { $this->status = $status; }
}
