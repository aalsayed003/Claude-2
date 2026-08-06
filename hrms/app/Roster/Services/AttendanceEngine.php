<?php
namespace App\Roster\Services;

use App\Core\Database;
use App\Core\Config;

/**
 * Derives daily attendance from the assigned roster and raw punches.
 *
 * For each employee/day it:
 *   - finds the scheduled shift (roster)
 *   - collects that day's punches, orders them, and maps them to
 *     first-in / first-out / second-in / second-out
 *   - computes late-in, early-out, worked minutes and OT
 *   - flags odd punches, day-off, holiday, leave and absence
 */
class AttendanceEngine
{
    private Database $db;
    private int $graceLate;
    private int $graceEarly;
    private int $otThreshold;

    public function __construct(?Database $db = null)
    {
        $this->db          = $db ?: Database::app();
        $this->graceLate   = (int) Config::get('attendance.grace_late_min', 0);
        $this->graceEarly  = (int) Config::get('attendance.grace_early_min', 0);
        $this->otThreshold = (int) Config::get('attendance.ot_min_threshold', 30);
    }

    /** Rebuild attendance for every employee across a period. */
    public function rebuildPeriod(string $periodKey): int
    {
        [$start, $end] = period_bounds($periodKey);
        $emps = $this->db->all("SELECT id FROM employees WHERE active = 1");
        $count = 0;
        foreach ($emps as $e) {
            $count += $this->rebuildEmployee((int) $e['id'], $start, $end);
        }
        return $count;
    }

    /** Rebuild one employee's attendance across an inclusive date range. */
    public function rebuildEmployee(int $employeeId, string $start, string $end): int
    {
        $rosters = $this->indexBy(
            $this->db->all(
                "SELECT r.work_date, r.shift_id, s.*
                   FROM roster r JOIN shifts s ON s.id = r.shift_id
                  WHERE r.employee_id = :e AND r.work_date BETWEEN :a AND :b",
                [':e' => $employeeId, ':a' => $start, ':b' => $end]
            ),
            'work_date'
        );

        $leaves = $this->db->all(
            "SELECT from_date, to_date FROM leaves
              WHERE employee_id = :e AND from_date <= :b AND to_date >= :a",
            [':e' => $employeeId, ':a' => $start, ':b' => $end]
        );
        $holidays = array_column(
            $this->db->all("SELECT holiday_date FROM holidays WHERE holiday_date BETWEEN :a AND :b",
                [':a' => $start, ':b' => $end]),
            'holiday_date'
        );

        $n = 0;
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $this->rebuildDay($employeeId, $date, $rosters[$date] ?? null, $leaves, $holidays);
            $n++;
        }
        return $n;
    }

    private function rebuildDay(int $empId, string $date, ?array $shift, array $leaves, array $holidays): void
    {
        $period = period_of($date);
        $punches = $this->dayPunches($empId, $date, $shift);

        $row = [
            'employee_id'  => $empId,
            'work_date'    => $date,
            'period_key'   => $period,
            'shift_id'     => $shift['shift_id'] ?? null,
            'act_first_in'  => $punches[0] ?? null,
            'act_first_out' => $punches[1] ?? null,
            'act_second_in' => $punches[2] ?? null,
            'act_second_out'=> $punches[3] ?? null,
            'punch_count'  => count(array_filter($punches)),
            'late_in_min'  => 0,
            'early_out_min'=> 0,
            'worked_min'   => 0,
            'ot_early_min' => 0,
            'ot_late_min'  => 0,
            'is_odd_punch' => 0,
            'status'       => 'no_punch',
        ];

        $rawCount = $this->rawPunchCount($empId, $date, $shift);
        $row['is_odd_punch'] = ($rawCount % 2 === 1) ? 1 : 0;

        // Classify the day.
        if ($shift && (int) $shift['is_day_off'] === 1) {
            $row['status'] = 'day_off';
        } elseif ($shift && (int) $shift['is_holiday'] === 1 || in_array($date, $holidays, true)) {
            $row['status'] = 'holiday';
        } elseif ($this->onLeave($date, $leaves)) {
            $row['status'] = 'leave';
        } elseif ($row['punch_count'] === 0) {
            $row['status'] = $shift ? 'absent' : 'no_punch';
        } else {
            $row['status'] = 'present';
            if ($shift) {
                $this->applyShiftMetrics($row, $shift, $date);
            }
            $row['worked_min'] = $this->workedMinutes($punches);
        }

        $this->upsert($row);
    }

    /** Compute late-in / early-out / OT against the scheduled shift. */
    private function applyShiftMetrics(array &$row, array $shift, string $date): void
    {
        $schedIn  = $this->at($date, $shift['first_in']);
        $schedOut = $this->at($date, $shift['second_out'] ?: $shift['first_out']);
        if ($schedOut && $schedIn && strtotime($schedOut) <= strtotime($schedIn)) {
            $schedOut = date('Y-m-d H:i:s', strtotime($schedOut . ' +1 day')); // night shift
        }

        $firstIn = $row['act_first_in'];
        $lastOut = $row['act_second_out'] ?: $row['act_first_out'];

        if ($schedIn && $firstIn) {
            $diff = (strtotime($firstIn) - strtotime($schedIn)) / 60;
            if ($diff > $this->graceLate) {
                $row['late_in_min'] = (int) round($diff);
            } elseif ($diff < 0 && abs($diff) >= $this->otThreshold) {
                $row['ot_early_min'] = (int) round(abs($diff));
            }
        }
        if ($schedOut && $lastOut) {
            $diff = (strtotime($schedOut) - strtotime($lastOut)) / 60;
            if ($diff > $this->graceEarly) {
                $row['early_out_min'] = (int) round($diff);
            } elseif ($diff < 0 && abs($diff) >= $this->otThreshold) {
                $row['ot_late_min'] = (int) round(abs($diff));
            }
        }
    }

    /** Sum worked minutes across the first/second punch pairs. */
    private function workedMinutes(array $p): int
    {
        $min = 0;
        if (!empty($p[0]) && !empty($p[1])) {
            $min += max(0, (strtotime($p[1]) - strtotime($p[0])) / 60);
        }
        if (!empty($p[2]) && !empty($p[3])) {
            $min += max(0, (strtotime($p[3]) - strtotime($p[2])) / 60);
        }
        return (int) round($min);
    }

    /**
     * Return an ordered [firstIn, firstOut, secondIn, secondOut] for the day.
     * Split shifts get four slots; single shifts fill the first pair.
     */
    private function dayPunches(int $empId, string $date, ?array $shift): array
    {
        $rows = $this->rawPunchesForDay($empId, $date, $shift);
        $times = array_map(fn($r) => $r['punch_time'], $rows);
        sort($times);

        $slots = [null, null, null, null];
        foreach (array_values($times) as $i => $t) {
            if ($i < 4) {
                $slots[$i] = $t;
            } else {
                $slots[3] = $t; // keep the latest as final out
            }
        }
        // For a non-split shift, collapse to first-in / last-out semantics
        // only when we have exactly 2 punches -> already correct.
        $isSplit = $shift && !empty($shift['second_in']);
        if (!$isSplit && count($times) > 2) {
            // first punch = in, last punch = out; middles ignored for pairing
            $slots = [$times[0], end($times), null, null];
        }
        return $slots;
    }

    private function rawPunchesForDay(int $empId, string $date, ?array $shift): array
    {
        // Night shifts can pull punches into the early hours of the next day.
        $crosses = $shift && (int) ($shift['crosses_midnight'] ?? 0) === 1;
        $from = $date . ' 00:00:00';
        $to   = $crosses
            ? date('Y-m-d H:i:s', strtotime($date . ' +1 day 11:59:59'))
            : $date . ' 23:59:59';
        return $this->db->all(
            "SELECT punch_time FROM punches
              WHERE employee_id = :e AND punch_time BETWEEN :a AND :b
              ORDER BY punch_time ASC",
            [':e' => $empId, ':a' => $from, ':b' => $to]
        );
    }

    private function rawPunchCount(int $empId, string $date, ?array $shift): int
    {
        return count($this->rawPunchesForDay($empId, $date, $shift));
    }

    private function onLeave(string $date, array $leaves): bool
    {
        foreach ($leaves as $l) {
            if ($date >= $l['from_date'] && $date <= $l['to_date']) {
                return true;
            }
        }
        return false;
    }

    private function at(string $date, ?string $time): ?string
    {
        if (!$time) {
            return null;
        }
        return $date . ' ' . substr($time, 0, 8);
    }

    private function indexBy(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[$r[$key]] = $r;
        }
        return $out;
    }

    private function upsert(array $row): void
    {
        $existing = $this->db->one(
            "SELECT id FROM attendance WHERE employee_id = :e AND work_date = :d",
            [':e' => $row['employee_id'], ':d' => $row['work_date']]
        );
        $row['computed_at'] = date('Y-m-d H:i:s');
        if ($existing) {
            $this->db->update('attendance', $row, 'id = :id', [':id' => $existing['id']]);
        } else {
            $this->db->insert('attendance', $row);
        }
    }
}
