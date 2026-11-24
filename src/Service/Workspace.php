<?php

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use IntlDateFormatter;

final readonly class Workspace
{
    private const PEOPLE = 'SELECT e.id, e.first_name, e.last_name, e.email, e.code, e.job, e.phone, e.active,
        e.archived, e.role, e.department_id, e.hired_on, e.left_on, d.name AS department
        FROM employee e LEFT JOIN department d ON d.id = e.department_id';
    private const REQUESTS = 'SELECT r.*, e.first_name, e.last_name, e.department_id,
        d.name AS department, t.name AS type, t.tone, t.icon FROM leave_request r
        JOIN employee e ON e.id = r.employee_id JOIN leave_type t ON t.id = r.leave_type_id
        LEFT JOIN department d ON d.id = e.department_id';

    public function __construct(private Connection $db, private WorkCalendar $calendar)
    {
    }

    public function dashboard(DateTimeImmutable $today, int $months, int $department): array
    {
        $end = $today->format('Y-m-d');
        $range = $today->modify('first day of this month')->modify('-'.($months - 1).' months');
        $workdays = $this->calendar->schedule()['days'];
        $workingToday = $this->calendar->units($today, $today, $workdays) > 0;
        $active = array_values(array_filter($this->people(department: $department), static fn (array $person) =>
            $person['active'] && (!$person['hired_on'] || $person['hired_on'] <= $end)));
        $all = $this->requests(department: $department, min: $range->format('Y-m-d'));
        $todayRows = $workingToday ? array_values(array_filter($all, fn (array $row) =>
            $row['status'] === 'approved' && $row['start_on'] <= $end && $row['end_on'] >= $end
            && $this->calendar->isScheduledDay($today, (int) $row['workdays']))) : [];
        $pending = (int) $this->db->fetchOne("SELECT count(*) FROM leave_request r JOIN employee e ON e.id = r.employee_id
            WHERE r.status = 'pending' AND (CAST(? AS INTEGER) = 0 OR e.department_id = ?)", [$department, $department]);
        $plan = $workingToday ? (int) $this->db->fetchOne('SELECT count(*) FROM employee e
            WHERE e.active = 1 AND e.archived = 0
            AND (e.hired_on IS NULL OR e.hired_on <= ?) AND (e.left_on IS NULL OR e.left_on >= ?)
            AND (CAST(? AS INTEGER) = 0 OR e.department_id = ?)',
            [$end, $end, $department, $department]) : 0;
        $peopleToday = count(array_unique(array_column($todayRows, 'employee_id')));
        $group = ['mint' => 'Congés payés', 'violet' => 'RTT', 'amber' => 'Autres absences'];
        $chart = [];
        $formatter = new IntlDateFormatter('fr_FR', timezone: $today->getTimezone(), pattern: 'MMM');
        for ($month = $range; $month <= $today; $month = $month->modify('+1 month')) {
            $chart[] = ['label' => rtrim($formatter->format($month), '.'), 'mint' => 0, 'violet' => 0, 'amber' => 0];
            $i = array_key_last($chart);
            $lastDay = $month->modify('last day of this month');
            foreach ($all as $row) {
                if ($row['status'] !== 'approved' || $row['start_on'] > $lastDay->format('Y-m-d')
                    || $row['end_on'] < $month->format('Y-m-d')) {
                    continue;
                }
                $from = max($month, new DateTimeImmutable($row['start_on']));
                $until = min($lastDay, new DateTimeImmutable($row['end_on']));
                $tone = $row['type'] === 'Congés payés' ? 'mint' : ($row['type'] === 'RTT' ? 'violet' : 'amber');
                $chart[$i][$tone] += $this->calendar->units($from, $until, (int) $row['workdays'],
                    $from->format('Y-m-d') === $row['start_on'] ? (int) $row['start_slot'] : 0,
                    $until->format('Y-m-d') === $row['end_on'] ? (int) $row['end_slot'] : 1) / 2;
            }
        }

        return [
            'active' => count($active),
            'departmentsCount' => count(array_unique(array_filter(array_column($active, 'department_id')))),
            'todayRows' => $todayRows,
            'peopleToday' => $peopleToday,
            'pending' => $pending,
            'planned' => max(0, $plan - $peopleToday),
            'coverage' => $plan ? (int) round(100 * max(0, $plan - $peopleToday) / $plan) : null,
            'recent' => array_slice($this->requests(department: $department), 0, 5),
            'chart' => $chart,
            'legend' => $group,
        ];
    }

    public function people(int $department = 0, bool $includeArchived = false): array
    {
        return $this->db->fetchAllAssociative(self::PEOPLE.'
            WHERE (CAST(? AS INTEGER) = 1 OR e.archived = 0) AND (CAST(? AS INTEGER) = 0 OR e.department_id = ?)
            ORDER BY e.first_name, e.last_name, e.id', [(int) $includeArchived, $department, $department]);
    }

    public function person(int $id): array|false
    {
        return $this->db->fetchAssociative(self::PEOPLE.' WHERE e.id = ?', [$id]);
    }

    public function requests(
        int $employee = 0,
        int $department = 0,
        string $status = '',
        string $min = '',
        string $max = '',
        int $type = 0,
    ): array {
        return $this->db->fetchAllAssociative(self::REQUESTS.'
            WHERE (CAST(? AS INTEGER) = 0 OR r.employee_id = ?) AND (CAST(? AS INTEGER) = 0 OR e.department_id = ?)
            AND (CAST(? AS INTEGER) = 0 OR r.leave_type_id = ?) AND (? = \'\' OR r.status = ?)
            AND (? = \'\' OR r.end_on >= ?) AND (? = \'\' OR r.start_on <= ?)
            ORDER BY r.created_at DESC, r.id DESC',
            [$employee, $employee, $department, $department, $type, $type, $status, $status, $min, $min, $max, $max]);
    }

    public function request(int $id): array|false
    {
        return $this->db->fetchAssociative(self::REQUESTS.' WHERE r.id = ?', [$id]);
    }

    public function departments(bool $onlyActive = false): array
    {
        return $this->db->fetchAllAssociative('SELECT d.*, count(e.id) AS employees FROM department d
            LEFT JOIN employee e ON e.department_id = d.id AND e.active = 1 AND e.archived = 0
            WHERE (CAST(? AS INTEGER) = 0 OR d.active = 1) GROUP BY d.id ORDER BY d.name', [(int) $onlyActive]);
    }

    public function leaveTypes(bool $onlyActive = false, bool $employee = false): array
    {
        return $this->db->fetchAllAssociative('SELECT t.*, c.name AS counter FROM leave_type t
            LEFT JOIN counter_type c ON c.id = t.counter_type_id
            WHERE (CAST(? AS INTEGER) = 0 OR t.active = 1) AND (CAST(? AS INTEGER) = 0 OR t.employee_visible = 1)
            ORDER BY t.id', [(int) $onlyActive, (int) $employee]);
    }

    public function counters(bool $onlyActive = false): array
    {
        return $this->db->fetchAllAssociative('SELECT id, name, color, active FROM counter_type
            WHERE listed = 1 AND (CAST(? AS INTEGER) = 0 OR active = 1) ORDER BY id', [(int) $onlyActive]);
    }

    public function balances(int $employee = 0): array
    {
        return $this->db->fetchAllAssociative('SELECT b.*, c.name, c.color, e.first_name, e.last_name, e.code,
            coalesce((SELECT sum(r.units) FROM leave_request r WHERE r.employee_id = b.employee_id
            AND r.counter_type_id = b.counter_type_id AND r.status = \'pending\'), 0) AS pending,
            coalesce((SELECT sum(r.units) FROM leave_request r WHERE r.employee_id = b.employee_id
            AND r.counter_type_id = b.counter_type_id AND r.status = \'approved\'), 0) AS consumed
            FROM balance b JOIN employee e ON e.id = b.employee_id JOIN counter_type c ON c.id = b.counter_type_id
            WHERE c.listed = 1 AND (CAST(? AS INTEGER) = 0 OR b.employee_id = ?)
            ORDER BY e.first_name, e.last_name, e.id, c.id', [$employee, $employee]);
    }

    public function movementCount(int $employee): int
    {
        return (int) $this->db->fetchOne('SELECT count(*) FROM balance_movement WHERE (CAST(? AS INTEGER) = 0 OR employee_id = ?)',
            [$employee, $employee]);
    }

    public function movements(int $employee = 0, int $limit = 50, int $offset = 0): array
    {
        return $this->db->fetchAllAssociative('SELECT m.*, c.name, c.listed AS counter_listed, e.first_name, e.last_name,
            a.first_name AS actor_first_name, a.last_name AS actor_last_name FROM balance_movement m
            JOIN counter_type c ON c.id = m.counter_type_id
            JOIN employee e ON e.id = m.employee_id JOIN employee a ON a.id = m.actor_id
            WHERE (CAST(? AS INTEGER) = 0 OR e.id = ?) ORDER BY m.id DESC LIMIT ? OFFSET ?',
            [$employee, $employee, $limit, $offset], [
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER,
            ]);
    }

    public function notifications(int $employee): array
    {
        return $this->db->fetchAllAssociative('SELECT * FROM notification WHERE employee_id = ? ORDER BY id DESC LIMIT 30', [$employee]);
    }

    public function planning(DateTimeImmutable $week, int $department, int $type, array $people, int $employee = 0): array
    {
        $end = $week->modify('+6 days');
        $workdays = $this->calendar->schedule()['days'];
        $days = [];
        $holidays = array_column($this->db->fetchAllAssociative('SELECT day, name FROM holiday'), 'name', 'day');
        for ($day = $week; $day <= $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $days[] = [
                'date' => $day, 'key' => $key, 'nonWorking' => isset($holidays[$key]) || !$this->calendar->isScheduledDay($day, $workdays),
                'holiday' => $holidays[$key] ?? null,
            ];
        }
        $eventsByEmployee = [];
        if ($people) {
            $displayed = array_flip(array_column($people, 'id'));
            $requests = $this->requests($employee, $department, min: $week->format('Y-m-d'), max: $end->format('Y-m-d'), type: $type);
            foreach ($requests as $request) {
                if (!isset($displayed[$request['employee_id']]) || !in_array($request['status'], ['approved', 'pending'], true)) {
                    continue;
                }
                $start = new DateTimeImmutable($request['start_on']);
                $stop = new DateTimeImmutable($request['end_on']);
                $eventsByEmployee[$request['employee_id']][] = $request + [
                    'column' => (int) $week->diff(max($start, $week))->format('%a') + 2,
                    'span' => (int) max($start, $week)->diff(min($stop, $end))->format('%a') + 1,
                ];
            }
        }
        $rows = array_map(static fn (array $person) => $person + ['events' => $eventsByEmployee[$person['id']] ?? []], $people);

        return ['days' => $days, 'rows' => $rows, 'week' => $week, 'end' => $end];
    }
}
