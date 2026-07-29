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
            // ShiftID (old) and ChangeShiftID (new) are NOT NULL in DR_ChangeSchedule,
            // so coalesce a missing value to 0 rather than NULL (which crashes the
            // insert). The controller backstop fills the old shift from the roster.
            'ShiftID'       => ($d['old_shift_id'] ?? '') !== '' ? (int) $d['old_shift_id'] : 0,
            'ChangeShiftID' => ($d['new_shift_id'] ?? '') !== '' ? (int) $d['new_shift_id'] : 0,
            // AgainstFor is a tinyint (a numeric code), not a date, despite the old UI
            // having a date picker for it. Since the field is no longer collected from
            // the user, default to 0 (the column is NOT NULL so it can't be left out).
            'AgainstFor'    => is_numeric($d['change_against_date'] ?? null) ? (int) $d['change_against_date'] : 0,
            // ClaimTime is an int and NOT NULL — default to 0 when not supplied
            // (it isn't collected from the user), never NULL.
            'ClaimTime'     => is_numeric($d['claim_time'] ?? null) ? (int) $d['claim_time'] : 0,
            'RejectReason'  => null,
            'StateID'       => (int) Config::get('legacy.dr_initial_state', 1),
        ];
        if (!$this->db->isIdentity($t, 'RequestID')) {
            $row = ['RequestID' => $this->db->nextId($t, 'RequestID')] + $row;
        }
        $this->db->insert($t, $row);
        return (int) ($row['RequestID'] ?? 0);
    }

    /** One schedule-change request by id, plus the employee's DepartmentId for category routing. */
    public function find(int $id): ?array
    {
        $t = lt('change_sched');
        $emp = lt('employee');
        return $this->db->one(
            "SELECT sc.RequestID, sc.EmployeeID, sc.ScheduleMonth, sc.ScheduleDay, sc.ShiftID,
                    sc.ChangeShiftID, sc.AgainstFor, sc.ClaimTime, sc.RejectReason, sc.StateID,
                    e.DepartmentId
               FROM {$t} sc
               LEFT JOIN {$emp} e ON e.ID = sc.EmployeeID
              WHERE sc.RequestID = :id",
            [':id' => $id]
        );
    }

    /** Schedule-change requests still in the approval chain (pending or first-gate-approved). */
    public function pendingForApproval(string $from, string $to): array
    {
        $t   = lt('change_sched');
        $emp = lt('employee');
        $shift = lt('shift');
        $st  = \App\Services\ScheduleChangeFlow::states();
        $in  = implode(',', [(int) $st['pending'], (int) $st['head_ok']]);
        try {
            $rows = $this->db->all(
                "SELECT sc.RequestID, sc.RequestDate, sc.EmployeeID, e.EmployeeId AS emp_code, e.Name AS emp_name,
                        e.DepartmentId, sc.ScheduleMonth, sc.ScheduleDay, sc.ClaimTime, sc.StateID,
                        os.Name AS old_code, ns.Name AS new_code
                   FROM {$t} sc
                   LEFT JOIN {$emp} e ON e.ID = sc.EmployeeID
                   LEFT JOIN {$shift} os ON os.ID = sc.ShiftID
                   LEFT JOIN {$shift} ns ON ns.ID = sc.ChangeShiftID
                  WHERE sc.StateID IN ({$in}) AND sc.RequestDate BETWEEN :a AND :b
                  ORDER BY sc.RequestDate DESC, sc.RequestID DESC",
                [':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']
            );
        } catch (\Throwable $e) {
            return [];   // table absent / different shape — no pending list
        }
        return array_map([$this, 'shapePending'], $rows);
    }

    private function shapePending(array $r): array
    {
        $work = null;
        if (!empty($r['ScheduleMonth'])) {
            $ym  = date('Y-m', strtotime((string) $r['ScheduleMonth']));
            $day = str_pad((string) (int) ($r['ScheduleDay'] ?? 1), 2, '0', STR_PAD_LEFT);
            $work = "{$ym}-{$day}";
        }
        return [
            'id'            => (int) $r['RequestID'],
            'emp_code'      => trim((string) ($r['emp_code'] ?? '')),
            'emp_name'      => trim((string) ($r['emp_name'] ?? '')),
            'department_id' => (int) ($r['DepartmentId'] ?? 0),
            'work_date'     => $work,
            'old_code'      => $r['old_code'] ?? null,
            'new_code'      => $r['new_code'] ?? null,
            'claim_time'    => $r['ClaimTime'] ?? null,
            'state_id'      => (int) ($r['StateID'] ?? 0),
        ];
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
