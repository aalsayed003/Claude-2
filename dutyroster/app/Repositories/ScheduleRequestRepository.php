<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Reads roster submission / approval data from Schedule_Request.
 * States (decoded from sp_wDREmployeeScheduleRequest):
 *   Approved 1 = Submitted, 2 = Department Head Approved, 3 = MD/COO/CNO Approved,
 *   Uploaded 1 = Uploaded/Applied to the live roster.
 */
class ScheduleRequestRepository
{
    public function __construct(private Database $db) {}

    /** Count of submissions still awaiting approval (dashboard "Schedules"). */
    public function pendingCount(): int
    {
        $t = lt('sched_req');
        $codes = Config::get('legacy.schedule_pending_codes', [1, 2]);
        $in = implode(',', array_map('intval', $codes));
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM {$t} WHERE Uploaded = 0 AND Approved IN ({$in})"
        );
    }

    /** Submissions in a date range, newest first, with a status label. */
    public function list(string $from, string $to): array
    {
        $t   = lt('sched_req');
        $dep = lt('department');
        $rows = $this->db->all(
            "SELECT r.ID AS id, r.ScheduleMonth, r.DepartmentId, d.Name AS dept_name,
                    r.Approved, r.Uploaded, r.Comments, r.DateTime AS submitted_at
               FROM {$t} r
               LEFT JOIN {$dep} d ON d.Id = r.DepartmentId
              WHERE r.DateTime BETWEEN :a AND :b
              ORDER BY r.DateTime DESC",
            [':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']
        );
        return array_map([$this, 'shape'], $rows);
    }

    private function shape(array $r): array
    {
        $states = Config::get('legacy.schedule_states', [
            1 => 'Submitted', 2 => 'Department Head Approved', 3 => 'MD/COO/CNO Approved',
        ]);
        $uploaded = (int) ($r['Uploaded'] ?? 0) === 1;
        $approved = (int) ($r['Approved'] ?? 0);
        $label = $uploaded ? 'Applied' : ($states[$approved] ?? 'Pending');
        $class = $uploaded ? 'applied'
            : ($approved >= 3 ? 'approved' : ($approved >= 1 ? 'present' : 'pending'));

        $month = $r['ScheduleMonth'] ? date('Y-m', strtotime((string) $r['ScheduleMonth'])) : '';
        return [
            'id'             => (int) $r['id'],
            'period_key'     => $month,
            'dept_name'      => $r['dept_name'] ?? null,
            'section_name'   => null,
            'submitted_name' => null,
            'submitted_at'   => $r['submitted_at'] ?? null,
            'status'         => $label,
            'status_class'   => $class,
            'can_act'        => false,   // approve/reject writes: next iteration
        ];
    }
}
