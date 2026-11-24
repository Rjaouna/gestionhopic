<?php

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DomainException;

final readonly class PaidLeaveAccrual
{
    public function __construct(private Connection $db, private LeaveManager $leaves)
    {
    }

    public function rule(): array
    {
        return $this->db->fetchAssociative('SELECT r.*, c.name AS counter_name
            FROM paid_leave_rule r LEFT JOIN counter_type c
            ON c.id = r.counter_type_id AND c.active = 1 AND c.listed = 1 WHERE r.id = 1')
            ?: throw new \LogicException('La règle de congés payés doit être initialisée par la migration.');
    }

    public function estimate(DateTimeImmutable $hired, DateTimeImmutable $today, array $rule): array
    {
        $months = $this->completeMonths($hired, $today);
        $opensOn = $this->anniversary($hired, (int) $rule['waiting_months']);
        $earned = $months * (int) $rule['monthly_units'];
        $units = $today >= $opensOn ? $earned : 0;

        return [
            'months' => $months, 'earnedUnits' => $earned, 'units' => $units,
            'opensOn' => $opensOn->format('Y-m-d'), 'available' => $today >= $opensOn,
            'key' => hash('sha256', implode('|', [
                $hired->format('Y-m-d'), $rule['counter_type_id'], $rule['monthly_units'], $rule['waiting_months'], $units, $months,
            ])),
        ];
    }

    public function configure(int $actor, ?int $counter, int $monthlyUnits, int $waitingMonths, bool $automatic): void
    {
        if ($monthlyUnits < 1 || $monthlyUnits > 62 || $waitingMonths < 0 || $waitingMonths > 120) {
            throw new DomainException('Vérifiez le nombre de jours par mois et le délai avant disponibilité.');
        }
        if ($automatic && !$counter) {
            throw new DomainException('Sélectionnez un compteur pour activer le crédit automatique.');
        }

        $this->db->transactional(function () use ($actor, $counter, $monthlyUnits, $waitingMonths, $automatic): void {
            $this->db->executeStatement('UPDATE paid_leave_rule SET id = id WHERE id = 1');
            if ($counter && !$this->db->fetchOne('SELECT 1 FROM counter_type WHERE id = ? AND listed = 1 AND active = 1', [$counter])) {
                throw new DomainException('Sélectionnez un compteur actif.');
            }
            $this->db->update('paid_leave_rule', [
                'counter_type_id' => $counter, 'monthly_units' => $monthlyUnits,
                'waiting_months' => $waitingMonths, 'automatic' => (int) $automatic, 'last_run_on' => null,
            ], ['id' => 1]);
            $this->leaves->audit($actor, 'Règle d’acquisition des congés payés modifiée.');
        });
    }

    // Called inside the employee creation transaction.
    public function openBalance(
        int $employee,
        int $actor,
        DateTimeImmutable $hired,
        DateTimeImmutable $today,
        ?int $units,
        string $key,
    ): void {
        $rule = $this->rule();
        $through = $today;
        if ($rule['counter_name'] !== null) {
            if ($units === null || $key === '') {
                throw new DomainException('Validez le solde initial de congés payés.');
            }
            $estimate = $this->estimate($hired, $today, $rule);
            if (!hash_equals($estimate['key'], $key)) {
                throw new DomainException('Le calcul du solde a changé. Relancez le calcul puis validez le montant.');
            }
            $reason = $units === $estimate['units']
                ? 'Solde initial validé à la création'
                : 'Solde initial corrigé à la création (proposition : '.str_replace('.', ',', (string) ($estimate['units'] / 2)).' j)';
            $this->leaves->creditPaidLeave($employee, (int) $rule['counter_type_id'], $actor, $units, $reason, opening: true);
            if (!$estimate['available'] && $units === 0) {
                $through = $hired;
            }
        } elseif ($key !== '' || $units !== null) {
            throw new DomainException('Le compteur de congés payés a changé. Rouvrez le formulaire de création.');
        }
        $this->db->update('employee', ['paid_leave_through' => $through->format('Y-m-d')], ['id' => $employee]);
    }

    public function creditDue(?DateTimeImmutable $today = null): int
    {
        $today ??= new DateTimeImmutable('today');
        $day = $today->format('Y-m-d');
        $rule = $this->rule();
        if (!$rule['automatic'] || $rule['counter_name'] === null
            || ($rule['last_run_on'] !== null && $rule['last_run_on'] >= $day)) {
            return 0;
        }

        return $this->db->transactional(function () use ($today, $day): int {
            // Serialize the checkpoint with balance updates and configuration changes.
            if (!$this->db->executeStatement('UPDATE paid_leave_rule SET last_run_on = last_run_on
                WHERE id = 1 AND automatic = 1 AND (last_run_on IS NULL OR last_run_on < ?)', [$day])) {
                return 0;
            }
            $rule = $this->rule();
            $actor = $this->db->fetchOne("SELECT id FROM employee WHERE role = 'ROLE_ADMIN' AND active = 1 AND archived = 0 ORDER BY id LIMIT 1");
            if (!$actor || $rule['counter_name'] === null) {
                return 0;
            }

            $employees = $this->db->fetchAllAssociative('SELECT id, hired_on, left_on, paid_leave_through
                FROM employee WHERE active = 1 AND archived = 0 AND hired_on <= ?', [$day]);
            $credited = 0;
            foreach ($employees as $employee) {
                $hired = new DateTimeImmutable($employee['hired_on']);
                if (!$employee['paid_leave_through']) {
                    $this->db->update('employee', ['paid_leave_through' => $day], ['id' => $employee['id']]);
                    continue;
                }
                $until = $employee['left_on'] ? min($today, new DateTimeImmutable($employee['left_on'])) : $today;
                if ($until < $this->anniversary($hired, (int) $rule['waiting_months'])) {
                    continue;
                }
                $months = $this->completeMonths($hired, $until);
                $covered = $this->completeMonths($hired, new DateTimeImmutable($employee['paid_leave_through']));
                if ($months <= $covered) {
                    continue;
                }
                $newMonths = $months - $covered;
                $this->leaves->creditPaidLeave((int) $employee['id'], (int) $rule['counter_type_id'], (int) $actor,
                    $newMonths * (int) $rule['monthly_units'], 'Acquisition automatique des congés payés · '.$newMonths.' mois');
                $this->db->update('employee', ['paid_leave_through' => $until->format('Y-m-d')], ['id' => $employee['id']]);
                ++$credited;
            }
            $this->db->update('paid_leave_rule', ['last_run_on' => $day], ['id' => 1]);

            return $credited;
        });
    }

    private function completeMonths(DateTimeImmutable $hired, DateTimeImmutable $until): int
    {
        if ($until < $hired) {
            return 0;
        }
        $months = ((int) $until->format('Y') - (int) $hired->format('Y')) * 12
            + (int) $until->format('n') - (int) $hired->format('n');

        return $until < $this->anniversary($hired, $months) ? $months - 1 : $months;
    }

    private function anniversary(DateTimeImmutable $hired, int $months): DateTimeImmutable
    {
        $target = $hired->modify('first day of this month')->modify('+'.$months.' months');

        return $target->setDate((int) $target->format('Y'), (int) $target->format('n'),
            min((int) $hired->format('j'), (int) $target->format('t')));
    }
}
