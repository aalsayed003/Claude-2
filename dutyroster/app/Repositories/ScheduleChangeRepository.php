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

    /**
     * Insert a change-of-schedule request into DR_ChangeSchedule. RequestID is
     * not an identity column in TestASSH (SELECT INTO dropped it), so we supply
     * MAX+1 when needed — same guard used for corrections. Returns the new id.
     */
    public function create(array $d): int
    {
        $t = lt('change_sched');
        $date = $d['work_date'];
        $row = [
            'RequestDate'   => date('Y-m-d H:i:s'),
            'EmployeeID'    => (int) $d['employee_id'],
            'ScheduleMonth' => date('Y-m-01', strtotime($date)),
            'ScheduleDay'   => (int) date('j', strtotime($date)),
            'ShiftID'       => ($d['old_shift_id'] ?? '') !== '' ? (int) $d['old_shift_id'] : null,
            'ChangeShiftID' => ($d['new_shift_id'] ?? '') !== '' ? (int) $d['new_shift_id'] : null,
            'AgainstFor'    => ($d['change_against_date'] ?? '') ?: null,
            'ClaimTime'     => ($d['claim_time'] ?? '') ?: null,
            'RejectReason'  => null,
            'StateID'       => (int) Config::get('legacy.dr_initial_state', 1),
        ];
        if (!$this->db->isIdentity($t, 'RequestID')) {
            $row = ['RequestID' => $this->db->nextId($t, 'RequestID')] + $row;
        }
        $this->db->insert($t, $row);
        return (int) ($row['RequestID'] ?? 0);
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
