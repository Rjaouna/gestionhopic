<?php

namespace App\Service;

use App\Entity\Employee;
use App\Entity\LeaveRequest;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Workflow\Registry;

final readonly class LeaveManager
{
    public function __construct(
        private Connection $db,
        private WorkCalendar $calendar,
        private Registry $workflows,
        #[Autowire('%kernel.project_dir%/var/attachments')] public string $attachmentDirectory,
    ) {
    }

    public function availableType(int $id, bool $admin): array|false
    {
        $type = $this->db->fetchAssociative('SELECT * FROM leave_type WHERE id = ? AND active = 1', [$id]);

        return $type && ($admin || $type['employee_visible']) ? $type : false;
    }

    public function create(Employee $actor, array $input, ?array $attachment = null): int
    {
        $employee = (int) ($input['employee'] ?? $actor->id);
        $admin = $actor->role === 'ROLE_ADMIN';
        if (!$admin) {
            $employee = $actor->id;
        }
        $start = $this->calendar->date($input['start'] ?? '');
        $end = $this->calendar->date($input['end'] ?? '');
        $startSlot = ($input['startSlot'] ?? '') === 'afternoon' ? 1 : 0;
        $endSlot = ($input['endSlot'] ?? '') === 'morning' ? 0 : 1;
        $comment = trim($input['comment'] ?? '');
        if (!$start || !$end || $end < $start || $end > $start->modify('+1 year')
            || ($start == $end && $startSlot > $endSlot)) {
            throw new DomainException('Vérifiez les dates et les demi-journées de votre demande.');
        }
        if (mb_strlen($comment) > 2000) {
            throw new DomainException('Le commentaire doit contenir au maximum 2 000 caractères.');
        }

        return $this->db->transactional(function () use ($actor, $admin, $employee, $input, $start, $end, $startSlot, $endSlot, $comment, $attachment) {
            $this->db->executeStatement('UPDATE employee SET active = active WHERE id = ?', [$employee]);
            $person = $this->db->fetchAssociative('SELECT hired_on, left_on FROM employee
                WHERE id = ? AND active = 1 AND archived = 0', [$employee]);
            $type = $this->availableType((int) ($input['type'] ?? 0), $admin);
            if (!$person || !$type) {
                throw new DomainException('Sélectionnez un collaborateur et un type d’absence disponibles.');
            }
            $from = $start->format('Y-m-d');
            $until = $end->format('Y-m-d');
            if (($person['hired_on'] && $from < $person['hired_on']) || ($person['left_on'] && $until > $person['left_on'])) {
                throw new DomainException('La période doit être comprise dans les dates du contrat.');
            }
            if (!$type['allow_half_days'] && ($startSlot !== 0 || $endSlot !== 1)) {
                throw new DomainException('Ce type d’absence se demande en journées complètes.');
            }
            if ($type['requires_attachment'] && !$attachment) {
                throw new DomainException('Ajoutez le justificatif demandé pour ce type d’absence.');
            }
            if (!$type['allow_attachment'] && $attachment) {
                throw new DomainException('Ce type d’absence ne permet pas de joindre un justificatif.');
            }
            $workdays = $this->calendar->schedule()['days'];
            $units = $this->calendar->units($start, $end, $workdays, $startSlot, $endSlot);
            if (!$units) {
                throw new DomainException('Cette période ne contient aucun jour travaillé.');
            }
            if ($this->db->fetchOne('SELECT 1 FROM leave_request WHERE employee_id = ?
                AND status IN (\'pending\', \'approved\')
                AND (end_on > ? OR (end_on = ? AND end_slot >= ?))
                AND (start_on < ? OR (start_on = ? AND start_slot <= ?)) LIMIT 1',
                [$employee, $from, $from, $startSlot, $until, $until, $endSlot])) {
                throw new DomainException('Une demande en cours ou acceptée couvre déjà une partie de cette période.');
            }
            $counter = $type['counter_type_id'];
            if ($counter && $units > $this->balance($employee, (int) $counter)) {
                throw new DomainException('Le solde disponible ne couvre pas cette demande.');
            }
            $status = $type['requires_approval'] ? 'pending' : 'approved';
            $now = $this->now();
            $this->db->insert('leave_request', [
                'employee_id' => $employee, 'leave_type_id' => $type['id'], 'counter_type_id' => $counter,
                'start_on' => $from, 'end_on' => $until, 'start_slot' => $startSlot, 'end_slot' => $endSlot,
                'units' => $units, 'workdays' => $workdays, 'comment' => $comment, 'status' => $status, 'decision_note' => '',
                'created_at' => $now, 'decided_by_id' => $status === 'approved' ? $actor->id : null,
                'decided_at' => $status === 'approved' ? $now : null,
                'attachment' => $attachment['key'] ?? null, 'attachment_name' => $attachment['name'] ?? null,
            ]);
            $id = (int) $this->db->lastInsertId();
            if ($status === 'approved' && $counter) {
                $this->changeBalance($employee, (int) $counter, $actor->id, -$units, 'debit', $type['name'], $id);
            }
            if ($status === 'pending') {
                foreach ($this->db->fetchFirstColumn("SELECT id FROM employee WHERE role = 'ROLE_ADMIN' AND active = 1 AND archived = 0 AND id <> ?", [$actor->id]) as $recipient) {
                    $this->notify((int) $recipient, 'Une demande de '.$type['name'].' est à traiter.');
                }
            } elseif ($employee !== $actor->id) {
                $this->notify($employee, 'Une absence a été enregistrée dans votre calendrier.');
            }
            $this->audit($actor->id, 'Demande n°'.$id.' enregistrée.');

            return $id;
        });
    }

    public function decide(Employee $actor, int $id, string $transition, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) > 500) {
            throw new DomainException('Le motif ne peut pas dépasser 500 caractères.');
        }
        $this->db->transactional(function () use ($actor, $id, $transition, $reason) {
            $this->db->executeStatement('UPDATE leave_request SET status = status WHERE id = ?', [$id]);
            $row = $this->db->fetchAssociative('SELECT * FROM leave_request WHERE id = ?', [$id]);
            if (!$row || ($actor->role !== 'ROLE_ADMIN' && ((int) $row['employee_id'] !== $actor->id
                || $transition !== 'cancel' || $row['status'] !== 'pending'))) {
                throw new DomainException('Cette action est indisponible pour cette demande.');
            }
            $request = new LeaveRequest();
            $request->status = $row['status'];
            $workflow = $this->workflows->get($request, 'leave');
            if (!$workflow->can($request, $transition)) {
                throw new DomainException('Le statut de cette demande ne permet plus cette action.');
            }
            $workflow->apply($request, $transition);
            $delta = match (true) {
                $request->status === 'approved' => -(int) $row['units'],
                $row['status'] === 'approved' && $request->status === 'cancelled' => (int) $row['units'],
                default => 0,
            };
            if ($delta && $row['counter_type_id']) {
                $this->changeBalance((int) $row['employee_id'], (int) $row['counter_type_id'], $actor->id,
                    $delta, $delta < 0 ? 'debit' : 'refund', $reason !== '' ? $reason : 'Demande n°'.$id, $id);
            }
            $this->db->update('leave_request', [
                'status' => $request->status, 'decision_note' => $reason,
                'decided_by_id' => $actor->id, 'decided_at' => $this->now(),
            ], ['id' => $id]);
            $message = match ($request->status) {
                'approved' => 'Votre demande a été acceptée.',
                'refused' => 'Votre demande a été refusée.',
                default => 'Votre demande a été annulée.',
            };
            $this->notify((int) $row['employee_id'], $message);
            $this->audit($actor->id, 'Statut mis à jour pour la demande n°'.$id.'.');
        });
    }

    public function adjust(Employee $actor, int $employee, int $counter, string $operation, int $quantity, string $reason): void
    {
        $reason = trim($reason);
        if (!in_array($operation, ['set', 'add', 'remove'], true) || $quantity < 0) {
            throw new DomainException('Vérifiez le type d’opération et la quantité.');
        }
        if (mb_strlen($reason) > 500) {
            throw new DomainException('Le motif ne peut pas dépasser 500 caractères.');
        }
        $this->db->transactional(function () use ($actor, $employee, $counter, $operation, $quantity, $reason) {
            $this->db->executeStatement('UPDATE employee SET active = active WHERE id = ?', [$employee]);
            if (!$this->db->fetchOne('SELECT 1 FROM employee WHERE id = ? AND archived = 0', [$employee])
                || !$this->db->fetchOne('SELECT 1 FROM counter_type WHERE id = ? AND active = 1 AND listed = 1', [$counter])) {
                throw new DomainException('Sélectionnez un collaborateur et un compteur disponibles.');
            }
            $delta = match ($operation) {
                'set' => $quantity - $this->balance($employee, $counter),
                'remove' => -$quantity,
                default => $quantity,
            };
            $this->changeBalance($employee, $counter, $actor->id, $delta, 'adjustment', $reason);
            $this->audit($actor->id, 'Solde ajusté pour le collaborateur n°'.$employee.'.');
        });
    }

    public function audit(int $actor, string $message): void
    {
        $this->db->insert('audit_entry', ['actor_id' => $actor, 'message' => $message, 'created_at' => $this->now()]);
    }

    public function creditPaidLeave(int $employee, int $counter, int $actor, int $units, string $reason, bool $opening = false): void
    {
        $this->changeBalance($employee, $counter, $actor, $units, $opening ? 'opening_balance' : 'accrual', $reason);
    }

    private function notify(int $employee, string $message): void
    {
        $this->db->insert('notification', ['employee_id' => $employee, 'message' => $message, 'created_at' => $this->now()]);
    }

    private function balance(int $employee, int $counter): int
    {
        return (int) $this->db->fetchOne('SELECT units FROM balance WHERE employee_id = ? AND counter_type_id = ?', [$employee, $counter]);
    }

    private function changeBalance(int $employee, int $counter, int $actor, int $delta, string $kind, string $reason, ?int $request = null): void
    {
        $old = $this->balance($employee, $counter);
        $new = $old + $delta;
        if ($new < 0) {
            throw new DomainException('Le solde disponible est insuffisant pour cette opération.');
        }
        $this->db->executeStatement('INSERT INTO balance (employee_id, counter_type_id, units) VALUES (?, ?, ?)
            ON CONFLICT(employee_id, counter_type_id) DO UPDATE SET units = excluded.units', [$employee, $counter, $new]);
        $this->db->insert('balance_movement', [
            'employee_id' => $employee, 'counter_type_id' => $counter, 'actor_id' => $actor,
            'leave_request_id' => $request, 'old_units' => $old, 'new_units' => $new,
            'kind' => $kind, 'reason' => $reason, 'created_at' => $this->now(),
        ]);
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
