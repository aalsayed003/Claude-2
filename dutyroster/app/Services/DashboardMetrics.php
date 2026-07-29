<?php
namespace App\Services;

use App\Core\Database;

/**
 * Dashboard "today" counts derived from the SAME live source as View Attendance
 * (roster + raw punches via AttendanceView), so the dashboard can never disagree
 * with the attendance page. Replaces the previous mix of a pre-computed
 * DRMainDashBoard table (odd punch / absent) and hard-coded zeros (late / early).
 */
class DashboardMetrics
{
    /**
     * Count absent / odd-punch / late / early / day-off for everyone rostered on
     * $date, using live punch pairing. Bounded to a single day.
     * @return array{absent:int,odd_punch:int,late:int,early:int,day_off:int}
     */
    public static function todayCounts(Database $db, string $date): array
    {
        $hdr = lt('roster_hdr');
        $dtl = lt('roster_dtl');
        $emp = lt('employee');

        // Everyone with a roster row for the day (+ the codes AttendanceView needs).
        $people = $db->all(
            "SELECT DISTINCT h.Empid AS id, e.EmployeeId AS emp_id, e.EmpCode AS emp_code
               FROM {$dtl} d
               JOIN {$hdr} h ON h.ID = d.AllotId AND h.Deleted = 0
               JOIN {$emp} e ON e.ID = h.Empid
              WHERE d.Deleted = 0 AND d.ShiftDate BETWEEN :a AND :b",
            [':a' => $date, ':b' => $date . ' 23:59:59']
        );

        $counts = ['absent' => 0, 'odd_punch' => 0, 'late' => 0, 'early' => 0, 'day_off' => 0];
        foreach ($people as $p) {
            $rows = AttendanceView::legacyRows($db, [
                'emp_id'   => $p['emp_id'],
                'emp_code' => $p['emp_code'],
            ], (int) $p['id'], $date, $date);
            $r = $rows[0] ?? null;
            if (!$r) continue;

            switch ($r['status']) {
                case 'day_off':
                case 'holiday': $counts['day_off']++; break;
                case 'absent':  $counts['absent']++;  break;
            }
            if (!empty($r['is_odd_punch']))            $counts['odd_punch']++;
            if (($r['late_in_min']   ?? 0) > 0)        $counts['late']++;
            if (($r['early_out_min'] ?? 0) > 0)        $counts['early']++;
        }
        return $counts;
    }
}
