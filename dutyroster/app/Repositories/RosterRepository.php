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

    /**
     * Write an approved schedule-change onto the live roster: set the shift for
     * one employee/day to $newShiftId, creating the month header if it doesn't
     * exist yet. Called when HR applies a Change-of-Schedule request, so the
     * approved change actually takes effect (not just a status flag).
     */
    public function applyShiftChange(int $employeeId, string $date, int $newShiftId): void
    {
        $hdr = lt('roster_hdr');
        $dtl = lt('roster_dtl');
        $shift = lt('shift');
        $monthStart = date('Y-m-01', strtotime($date));

        $header = $this->db->one(
            "SELECT ID FROM {$hdr} WHERE Empid = :e AND CurrentMonth = :m AND Deleted = 0",
            [':e' => $employeeId, ':m' => $monthStart]
        );
        if ($header) {
            $allotId = (int) $header['ID'];
        } else {
            // Identity-safe: supply ID when the header PK isn't an IDENTITY column
            // (SELECT INTO drops IDENTITY, which otherwise crashes on NULL ID).
            // operatorid is NOT NULL: record the operator (HR user applying),
            // falling back to the employee when there's no active session.
            $operator = \App\Core\Auth::id() ?: $employeeId;
            $allotId = $this->db->insertLegacy($hdr, [
                'Empid' => $employeeId, 'CurrentMonth' => $monthStart,
                'Deleted' => 0, 'TotalHours' => 0, 'operatorid' => $operator,
            ], 'ID');
        }

        $hours = (float) ($this->db->value("SELECT TotalHours FROM {$shift} WHERE ID = :s", [':s' => $newShiftId]) ?? 0);
        $existing = $this->db->one(
            "SELECT AllotId FROM {$dtl} WHERE AllotId = :a AND ShiftDate = :d",
            [':a' => $allotId, ':d' => $date]
        );
        if ($existing) {
            $this->db->run(
                "UPDATE {$dtl} SET Shiftid = :s, Deleted = 0, TotalHours = :h WHERE AllotId = :a AND ShiftDate = :d",
                [':s' => $newShiftId, ':h' => $hours, ':a' => $allotId, ':d' => $date]
            );
        } else {
            // AllotShiftDetail has no ID/identity column; AllotId is the key.
            // ShiftDay and halfdayleavetype are NOT NULL with no default, so
            // both must be supplied or the INSERT crashes on SQL Server.
            $this->db->insert($dtl, [
                'AllotId' => $allotId, 'Shiftid' => $newShiftId,
                'ShiftDay' => date('l', strtotime($date)), 'ShiftDate' => $date,
                'Deleted' => 0, 'halfdayleavetype' => 0, 'TotalHours' => $hours,
            ]);
        }

        $sum = (float) $this->db->value(
            "SELECT COALESCE(SUM(TotalHours), 0) FROM {$dtl} WHERE AllotId = :a AND Deleted = 0",
            [':a' => $allotId]
        );
        $this->db->run("UPDATE {$hdr} SET TotalHours = :h WHERE ID = :id", [':h' => $sum, ':id' => $allotId]);
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
