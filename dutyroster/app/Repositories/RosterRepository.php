<?php
namespace App\Repositories;

use App\Core\Database;

/**
 * Reads the legacy roster: AllotShift (header, one per employee per month)
 * + AllotShiftDetail (one row per day, keyed by ShiftDate) + Shift master.
 *
 * Detail times (Intime/Outtime, InTime1/OutTime1) override the shift master
 * defaults when present; otherwise the shift's FromTime/ToTime are used.
 */
class RosterRepository
{
    public function __construct(private Database $db) {}

    /** Assigned roster for one employee over a date range, keyed by 'Y-m-d'. */
    public function forEmployeeRange(int $employeeId, string $start, string $end): array
    {
        $hdr = lt('roster_hdr');
        $dtl = lt('roster_dtl');
        $shift = lt('shift');

        $rows = $this->db->all(
            "SELECT d.ShiftDate AS work_date, d.Shiftid AS shift_id, s.Name AS code,
                    d.Intime, d.Outtime, d.InTime1, d.OutTime1, d.TotalHours AS d_hours,
                    s.FromTime, s.ToTime, s.FromTime1, s.ToTime1, s.TotalHours AS s_hours
               FROM {$dtl} d
               JOIN {$hdr} h ON h.ID = d.AllotId AND h.Deleted = 0
               JOIN {$shift} s ON s.ID = d.Shiftid
              WHERE h.Empid = :e AND d.Deleted = 0
                AND d.ShiftDate BETWEEN :a AND :b
              ORDER BY d.ShiftDate",
            [':e' => $employeeId, ':a' => $start, ':b' => $end . ' 23:59:59']
        );

        $out = [];
        foreach ($rows as $r) {
            $date = substr((string) $r['work_date'], 0, 10);
            $out[$date] = [
                'work_date'   => $date,
                'shift_id'    => (int) $r['shift_id'],
                'code'        => trim((string) $r['code']),
                'name'        => trim((string) $r['code']),
                'first_in'    => $this->time($r['Intime'],  $r['FromTime']),
                'first_out'   => $this->time($r['Outtime'], $r['ToTime']),
                'second_in'   => $this->time($r['InTime1'], $r['FromTime1']),
                'second_out'  => $this->time($r['OutTime1'],$r['ToTime1']),
                'total_hours' => (float) ($r['d_hours'] ?? $r['s_hours'] ?? 0),
            ];
        }
        return $out;
    }

    /** empId => assigned-day count over a date range (for the roster overview). */
    public function assignedDaysByEmployee(string $start, string $end): array
    {
        $hdr = lt('roster_hdr');
        $dtl = lt('roster_dtl');
        $rows = $this->db->all(
            "SELECT h.Empid AS id, COUNT(*) AS assigned_days
               FROM {$dtl} d
               JOIN {$hdr} h ON h.ID = d.AllotId AND h.Deleted = 0
              WHERE d.Deleted = 0 AND d.ShiftDate BETWEEN :a AND :b
              GROUP BY h.Empid",
            [':a' => $start, ':b' => $end . ' 23:59:59']
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['id']] = (int) $r['assigned_days'];
        }
        return $map;
    }

    /** Prefer the detail datetime; fall back to the shift-master varchar time. */
    private function time($detail, $shiftDefault): ?string
    {
        if ($detail !== null && $detail !== '') {
            return date('H:i', strtotime((string) $detail));
        }
        $s = trim((string) ($shiftDefault ?? ''));
        return $s !== '' ? $s : null;
    }
}
