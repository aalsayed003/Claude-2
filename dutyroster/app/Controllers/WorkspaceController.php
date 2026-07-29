<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\AttendanceView;
use App\Repositories\EmployeeRepository;
use App\Repositories\RosterRepository;
use App\Repositories\CorrectionRepository;
use App\Repositories\ReasonRepository;
use App\Repositories\ScheduleChangeRepository;
use App\Repositories\ShiftRepository;

/**
 * Merged "Attendance" workspace: View Attendance, Attendance Correction and
 * Change Schedule as tabs on one page (one employee/period selector shared
 * by all three), instead of three separate pages each asking the user to
 * pick the employee again. Loads all three tabs' data in a single request so
 * switching tabs is instant (no reload).
 */
class WorkspaceController extends Controller
{
    public function index(): void
    {
        Auth::require();
        $user  = Auth::user();
        $canPickAnyone = Auth::atLeast('dept_head');
        $empId = $canPickAnyone
            ? (int) $this->input('employee_id', $user['employee_id'] ?? 0)
            : (int) ($user['employee_id'] ?? 0);

        $period = $this->input('period', period_of(date('Y-m-d')));
        [$cutFrom, $cutTo] = period_bounds($period);
        $tab = $this->input('tab', 'attendance');

        $emp = null; $attendanceRows = []; $corrRequests = []; $reasons = [];
        $scRequests = []; $roster = []; $shifts = []; $employees = []; $allRequests = [];

        if (legacy_mode()) {
            $empRepo = new EmployeeRepository($this->db);
            $emp = $empId ? $empRepo->find($empId) : null;
            $employees = $canPickAnyone ? $empRepo->search('') : [];
            $shifts = (new ShiftRepository($this->db))->all();

            if ($emp) {
                $attendanceRows = AttendanceView::legacyRows($this->db, $emp, $empId, $cutFrom, $cutTo);
                $roster         = $this->scheduledForRange($empId, $cutFrom, $cutTo);
                $corrRequests   = (new CorrectionRepository($this->db))->forEmployee($empId, $cutFrom, $cutTo);
                $scRequests     = array_values(array_filter(
                    (new ScheduleChangeRepository($this->db))->forEmployee($empId),
                    fn($r) => !empty($r['work_date']) && $r['work_date'] >= $cutFrom && $r['work_date'] <= $cutTo
                ));

                // One combined, date-sorted feed of both request types for this period,
                // shown directly under the attendance table (see the per-day "Correct
                // Attendance" / "Change Schedule" buttons).
                foreach ($corrRequests as $r) {
                    $allRequests[] = [
                        'work_date' => $r['work_date'], 'type' => 'Correction',
                        'detail'    => $r['type_label'] ?? '', 'reason' => $r['reason'] ?? '',
                        'status'    => $r['status'] ?? '',
                    ];
                }
                foreach ($scRequests as $r) {
                    $allRequests[] = [
                        'work_date' => $r['work_date'], 'type' => 'Change Schedule',
                        'detail'    => trim(($r['old_code'] ?? '—') . ' → ' . ($r['new_code'] ?? '—')),
                        'reason'    => '', 'status' => $r['status'] ?? '',
                    ];
                }
                usort($allRequests, fn($a, $b) => strcmp($b['work_date'] ?? '', $a['work_date'] ?? ''));
            }
            $reasons = (new ReasonRepository($this->db))->all();
        }

        $this->view('workspace/index', [
            'title'          => 'Attendance',
            'tab'            => in_array($tab, ['attendance', 'correction', 'schedule'], true) ? $tab : 'attendance',
            'employees'      => $employees,
            'canPickAnyone'  => $canPickAnyone,
            'emp'            => $emp,
            'period'         => $period,
            'cutFrom'        => $cutFrom,
            'cutTo'          => $cutTo,
            'attendanceRows' => $attendanceRows,
            'roster'         => $roster,
            'shifts'         => $shifts,
            'reasons'        => $reasons,
            'corrRequests'   => $corrRequests,
            'scRequests'     => $scRequests,
            'allRequests'    => $allRequests,
        ]);
    }

    /**
     * The approved duty-roster schedule per day for an employee over a range,
     * keyed by 'Y-m-d' => [shift_id, code, first_in, first_out, second_in,
     * second_out, total_hours, split]. Both the Correction tab (what time to
     * reset a punch to) and the Change Schedule tab (auto-filled "Old Shift")
     * read from this same map.
     */
    private function scheduledForRange(int $empId, string $from, string $to): array
    {
        $rows = (new RosterRepository($this->db))->forEmployeeRange($empId, $from, $to);
        $out = [];
        foreach ($rows as $date => $r) {
            $norm = fn($t) => ($t !== null && trim((string) $t) !== '') ? date('H:i', strtotime((string) $t)) : null;
            $fi = $norm($r['first_in'] ?? null); $fo = $norm($r['first_out'] ?? null);
            $si = $norm($r['second_in'] ?? null); $so = $norm($r['second_out'] ?? null);
            $out[$date] = [
                'shift_id'    => (int) ($r['shift_id'] ?? 0),
                'code'        => $r['code'] ?? '',
                'first_in'    => $fi, 'first_out' => $fo,
                'second_in'   => $si, 'second_out' => $so,
                'total_hours' => (float) ($r['total_hours'] ?? 0),
                'split'       => ($si !== null || $so !== null),
            ];
        }
        return $out;
    }
}
