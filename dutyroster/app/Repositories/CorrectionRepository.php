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
                    r.Reason AS reason_name, cr.TypeId, cr.StateID, cr.Remarks
               FROM {$t} cr
               LEFT JOIN {$rt} r ON r.ReasonID = cr.ReasonID
              WHERE cr.EmployeeID = :e AND cr.DayFor BETWEEN :a AND :b
              ORDER BY cr.RequestDate DESC",
            [':e' => $employeeId, ':a' => $from, ':b' => $to . ' 23:59:59']
        );
        return array_map([$this, 'shape'], $rows);
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
        return $this->db->insert($t, $row);
    }

    private function shape(array $r): array
    {
        $states = Config::get('legacy.dr_states', [10 => 'Expired']);
        $type = (int) ($r['TypeId'] ?? 0);
        return [
            'id'           => (int) $r['RequestID'],
            'requested_at' => $r['RequestDate'] ?? null,
            'work_date'    => $r['DayFor'] ? substr((string) $r['DayFor'], 0, 10) : null,
            'reason'       => $r['reason_name'] ?? null,
            'type_label'   => in_array($type, [0, 2], true) ? 'Late-In' : 'Early-Out',
            'remarks'      => $r['Remarks'] ?? null,
            'status'       => $states[(int) ($r['StateID'] ?? 0)] ?? ('State ' . (int) ($r['StateID'] ?? 0)),
            'lines'        => 1,
        ];
    }
}
