<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Reads change-of-schedule requests from DR_ChangeSchedule.
 * Columns: RequestID, RequestDate, EmployeeID (= Employee.ID), ScheduleMonth,
 * ScheduleDay, ShiftID (old), ChangeShiftID (new), AgainstFor, ClaimTime,
 * RejectReason, StateID.
 */
class ScheduleChangeRepository
{
    public function __construct(private Database $db) {}

    public function forEmployee(int $employeeId, int $limit = 50): array
    {
        $t = lt('change_sched');
        $shift = lt('shift');
        $rows = $this->db->all(
            $this->db->limit(
                "SELECT sc.RequestID, sc.RequestDate, sc.ScheduleMonth, sc.ScheduleDay,
                        os.Name AS old_code, ns.Name AS new_code, sc.ClaimTime, sc.StateID
                   FROM {$t} sc
                   LEFT JOIN {$shift} os ON os.ID = sc.ShiftID
                   LEFT JOIN {$shift} ns ON ns.ID = sc.ChangeShiftID
                  WHERE sc.EmployeeID = :e
                  ORDER BY sc.RequestDate DESC", $limit),
            [':e' => $employeeId]
        );
        return array_map([$this, 'shape'], $rows);
    }

    private function shape(array $r): array
    {
        $states = Config::get('legacy.dr_states', [10 => 'Expired']);
        $work = null;
        if (!empty($r['ScheduleMonth'])) {
            $ym = date('Y-m', strtotime((string) $r['ScheduleMonth']));
            $day = str_pad((string) (int) ($r['ScheduleDay'] ?? 1), 2, '0', STR_PAD_LEFT);
            $work = "{$ym}-{$day}";
        }
        return [
            'work_date' => $work,
            'old_code'  => $r['old_code'] ?? null,
            'new_code'  => $r['new_code'] ?? null,
            'claim_time'=> $r['ClaimTime'] ?? null,
            'status'    => $states[(int) ($r['StateID'] ?? 0)] ?? ('State ' . (int) ($r['StateID'] ?? 0)),
        ];
    }
}
