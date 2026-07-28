<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Reads attendance-correction requests from DR_CorrectionRequest (consolidated
 * into the app DB). Columns: RequestID, RequestDate, EmployeeID (= Employee.ID),
 * DayFor, FirstIn/Out, SecondIn/Out, ReasonID (-> DR_OvertimeReason), TypeId
 * (0/2 = late-in, 1/3 = early-out), Remarks, StateID.
 */
class CorrectionRepository
{
    public function __construct(private Database $db) {}

    /** Correction requests for one employee (by Employee.ID) over a date range. */
    public function forEmployee(int $employeeId, string $from, string $to): array
    {
        $t = lt('correction_table') ?: 'DR_CorrectionRequest';
        $rt = lt('ot_reason');
        $rows = $this->db->all(
            "SELECT cr.RequestID, cr.RequestDate, cr.DayFor, cr.ReasonID,
                    r.Reason AS reason_name, cr.TypeId, cr.StateID, cr.Remarks,
                    cr.FirstIn, cr.FirstOut, cr.SecondIn, cr.SecondOut
               FROM {$t} cr
               LEFT JOIN {$rt} r ON r.ReasonID = cr.ReasonID
              WHERE cr.EmployeeID = :e AND cr.DayFor BETWEEN :a AND :b
              ORDER BY cr.RequestDate DESC",
            [':e' => $employeeId, ':a' => $from, ':b' => $to . ' 23:59:59']
        );
        return array_map([$this, 'shape'], $rows);
    }

    /** One correction request by id (raw legacy row). */
    public function find(int $id): ?array
    {
        $t = lt('correction_table') ?: 'DR_CorrectionRequest';
        return $this->db->one(
            "SELECT RequestID, EmployeeID, DayFor, FirstIn, FirstOut, SecondIn, SecondOut,
                    ReasonID, TypeId, Remarks, StateID
               FROM {$t} WHERE RequestID = :id",
            [':id' => $id]
        );
    }

    /** Corrections still in the approval chain (pending or dept-head-approved). */
    public function pendingForApproval(string $from, string $to): array
    {
        $t   = lt('correction_table') ?: 'DR_CorrectionRequest';
        $rt  = lt('ot_reason');
        $emp = lt('employee');
        $st  = \App\Services\CorrectionFlow::states();
        $in  = implode(',', [(int) $st['pending'], (int) $st['head_ok']]);
        try {
        $rows = $this->db->all(
            "SELECT cr.RequestID, cr.RequestDate, cr.EmployeeID, e.EmployeeId AS emp_code, e.Name AS emp_name,
                    cr.DayFor, cr.FirstIn, cr.FirstOut, cr.SecondIn, cr.SecondOut,
                    r.Reason AS reason_name, cr.TypeId, cr.StateID, cr.Remarks
               FROM {$t} cr
               LEFT JOIN {$emp} e ON e.ID = cr.EmployeeID
               LEFT JOIN {$rt} r  ON r.ReasonID = cr.ReasonID
              WHERE cr.StateID IN ({$in}) AND cr.DayFor BETWEEN :a AND :b
              ORDER BY cr.DayFor DESC, cr.RequestID DESC",
            [':a' => $from, ':b' => $to . ' 23:59:59']
        );
        return array_map([$this, 'shapePending'], $rows);
        } catch (\Throwable $e) {
            return [];   // correction table absent / different shape — no pending list
        }
    }

    /**
     * Applied corrections for one employee, keyed by 'Y-m-d', each giving the
     * punch slots to override with the approved (rostered) time.
     */
    public function appliedForEmployee(int $employeeId, string $from, string $to): array
    {
        $t = lt('correction_table') ?: 'DR_CorrectionRequest';
        $applied = \App\Services\CorrectionFlow::appliedState();
        try {
            $rows = $this->db->all(
                "SELECT DayFor, FirstIn, FirstOut, SecondIn, SecondOut
                   FROM {$t}
                  WHERE EmployeeID = :e AND StateID = :s AND DayFor BETWEEN :a AND :b",
                [':e' => $employeeId, ':s' => $applied, ':a' => $from, ':b' => $to . ' 23:59:59']
            );
        } catch (\Throwable $e) {
            return [];   // correction table absent — no overrides
        }
        $map = [];
        foreach ($rows as $r) {
            $date = substr((string) $r['DayFor'], 0, 10);
            $slots = [
                'act_first_in'   => $r['FirstIn']   ?: null,
                'act_first_out'  => $r['FirstOut']  ?: null,
                'act_second_in'  => $r['SecondIn']  ?: null,
                'act_second_out' => $r['SecondOut'] ?: null,
            ];
            // Merge if multiple corrections exist for the same day.
            $map[$date] = array_merge($map[$date] ?? [], array_filter($slots, fn($v) => $v !== null));
        }
        return $map;
    }

    private function shapePending(array $r): array
    {
        $tm = fn($v) => $v ? date('h:i a', strtotime((string) $v)) : null;
        $parts = [];
        if ($tm($r['FirstIn']))   $parts[] = 'First In → '  . $tm($r['FirstIn']);
        if ($tm($r['FirstOut']))  $parts[] = 'First Out → ' . $tm($r['FirstOut']);
        if ($tm($r['SecondIn']))  $parts[] = 'Second In → ' . $tm($r['SecondIn']);
        if ($tm($r['SecondOut'])) $parts[] = 'Second Out → '. $tm($r['SecondOut']);
        $state = (int) ($r['StateID'] ?? 0);
        return [
            'id'         => (int) $r['RequestID'],
            'emp_code'   => trim((string) ($r['emp_code'] ?? '')),
            'emp_name'   => trim((string) ($r['emp_name'] ?? '')),
            'work_date'  => $r['DayFor'] ? substr((string) $r['DayFor'], 0, 10) : null,
            'change'     => implode(', ', $parts),
            'reason'     => $r['reason_name'] ?? null,
            'remarks'    => $r['Remarks'] ?? null,
            'state_id'   => $state,
        ];
    }

    /** Count of pending corrections in a period (dashboard). */
    public function pendingCount(string $from, string $to): int
    {
        $t = lt('correction_table') ?: 'DR_CorrectionRequest';
        $pending = Config::get('legacy.dr_pending_states', [1, 3, 4, 5, 6]);
        $in = implode(',', array_map('intval', $pending));
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM {$t}
              WHERE StateID IN ({$in}) AND RequestDate BETWEEN :a AND :b",
            [':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']
        );
    }

    /**
     * Insert a new correction request. RequestID is assumed to be an identity
     * column (as in the legacy design), so it is not supplied. Times are
     * combined with the correction date; empty ones are stored NULL. Returns
     * the new RequestID.
     */
    public function create(array $d): int
    {
        $t = lt('correction_table') ?: 'DR_CorrectionRequest';
        $date = $d['work_date'];
        $mk = fn($time) => ($time ? $date . ' ' . $time . ':00' : null);

        $row = [
            'RequestDate' => date('Y-m-d H:i:s'),
            'EmployeeID'  => (int) $d['employee_id'],
            'MonthFor'    => date('Y-m-01', strtotime($date)),
            'DayFor'      => $date,
            'FirstIn'     => $mk($d['first_in']  ?? null),
            'FirstOut'    => $mk($d['first_out'] ?? null),
            'SecondIn'    => $mk($d['second_in'] ?? null),
            'SecondOut'   => $mk($d['second_out']?? null),
            'ReasonID'    => $d['reason_id'] !== '' ? (int) $d['reason_id'] : null,
            'TypeId'      => (int) ($d['type_id'] ?? 0),
            'Remarks'     => $d['remarks'] ?? '',
            'StateID'     => (int) Config::get('legacy.dr_initial_state', 1),
        ];
        // RequestID is not an identity column here — supply the next id.
        if (!$this->db->isIdentity($t, 'RequestID')) {
            $row = ['RequestID' => $this->db->nextId($t, 'RequestID')] + $row;
        }
        $this->db->insert($t, $row);
        return (int) ($row['RequestID'] ?? 0);
    }

    private function shape(array $r): array
    {
        $states = Config::get('legacy.dr_states', [10 => 'Expired']);
        $type = (int) ($r['TypeId'] ?? 0);

        // Label the request by which punches it corrects (and their target time).
        $tm = fn($v) => $v ? date('h:i a', strtotime((string) $v)) : null;
        $parts = [];
        if ($tm($r['FirstIn']   ?? null)) $parts[] = 'First In → '  . $tm($r['FirstIn']);
        if ($tm($r['FirstOut']  ?? null)) $parts[] = 'First Out → ' . $tm($r['FirstOut']);
        if ($tm($r['SecondIn']  ?? null)) $parts[] = 'Second In → ' . $tm($r['SecondIn']);
        if ($tm($r['SecondOut'] ?? null)) $parts[] = 'Second Out → '. $tm($r['SecondOut']);
        $label = $parts ? implode(', ', $parts) : (in_array($type, [0, 2], true) ? 'Late-In' : 'Early-Out');

        return [
            'id'           => (int) $r['RequestID'],
            'requested_at' => $r['RequestDate'] ?? null,
            'work_date'    => $r['DayFor'] ? substr((string) $r['DayFor'], 0, 10) : null,
            'reason'       => $r['reason_name'] ?? null,
            'type_label'   => $label,
            'remarks'      => $r['Remarks'] ?? null,
            'status'       => $states[(int) ($r['StateID'] ?? 0)] ?? ('State ' . (int) ($r['StateID'] ?? 0)),
            'lines'        => 1,
        ];
    }
}
