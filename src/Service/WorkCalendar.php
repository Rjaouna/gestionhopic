<?php

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class WorkCalendar
{
    public function __construct(private Connection $db)
    {
    }

    public function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return array{id: int, days: int} */
    public function schedule(): array
    {
        $row = $this->db->fetchAssociative('SELECT id, days FROM work_schedule');
        if (!$row) {
            throw new \LogicException('L’horaire de travail doit être initialisé par la migration.');
        }

        return ['id' => (int) $row['id'], 'days' => (int) $row['days']];
    }

    public function isScheduledDay(DateTimeImmutable $day, int $days): bool
    {
        return ($days & (1 << ((int) $day->format('N') - 1))) !== 0;
    }

    public function units(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $days,
        int $startSlot = 0,
        int $endSlot = 1,
    ): int {
        $holidays = array_flip($this->db->fetchFirstColumn(
            'SELECT day FROM holiday WHERE day BETWEEN ? AND ?',
            [$start->format('Y-m-d'), $end->format('Y-m-d')],
        ));
        $units = 0;
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            if (!$this->isScheduledDay($day, $days) || isset($holidays[$day->format('Y-m-d')])) {
                continue;
            }
            $units += 2 - ($day == $start ? $startSlot : 0) - ($day == $end ? 1 - $endSlot : 0);
        }

        return max(0, $units);
    }
}
