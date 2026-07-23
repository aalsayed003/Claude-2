<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $period = $this->input('period', period_of(date('Y-m-d')));
        [$start, $end] = period_bounds($period);
        $today = date('Y-m-d');

        if (legacy_mode()) {
            $counts = $this->legacyCounts($period, $start, $end, $today);
        } else {
            $counts = [
                'schedules' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM roster_submissions
                      WHERE period_key = :p AND status NOT IN ('approved','rejected')",
                    [':p' => $period]
                ),
                'corrections' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM correction_requests
                      WHERE period_key = :p AND status NOT IN ('applied','rejected')",
                    [':p' => $period]
                ),
                'schedule_changes' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM schedule_change_requests
                      WHERE period_key = :p AND status NOT IN ('applied','rejected')",
                    [':p' => $period]
                ),
                'odd_punch' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM attendance WHERE period_key = :p AND is_odd_punch = 1",
                    [':p' => $period]
                ),
                'absent_today' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM attendance WHERE work_date = :d AND status = 'absent'",
                    [':d' => $today]
                ),
                'dayoff_today' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM attendance WHERE work_date = :d AND status = 'day_off'",
                    [':d' => $today]
                ),
                'late_today' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM attendance WHERE work_date = :d AND late_in_min > 0",
                    [':d' => $today]
                ),
                'early_today' => (int) $this->db->value(
                    "SELECT COUNT(*) FROM attendance WHERE work_date = :d AND early_out_min > 0",
                    [':d' => $today]
                ),
            ];
        }

        $this->renderDashboard($period, $counts);
    }

    /** Dashboard counts from legacy tables (each guarded so one gap can't break the page). */
    private function legacyCounts(string $period, string $start, string $end, string $today): array
    {
        $safe = function (callable $fn): int {
            try { return (int) $fn(); } catch (\Throwable $e) { return 0; }
        };
        $dash = lt('dashboard');
        $dtl  = lt('roster_dtl');
        $shift = lt('shift');

        return [
            'schedules' => $safe(fn() => (new \App\Repositories\ScheduleRequestRepository($this->db))->pendingCount()),
            'corrections' => $safe(fn() => (new \App\Repositories\CorrectionRepository($this->db))->pendingCount($start, $end)),
            'schedule_changes' => $safe(function () use ($start, $end) {
                $cs = lt('change_sched');
                $pending = implode(',', array_map('intval', \App\Core\Config::get('legacy.dr_pending_states', [1, 3, 4, 5, 6])));
                return $this->db->value(
                    "SELECT COUNT(*) FROM {$cs} WHERE StateID IN ({$pending}) AND RequestDate BETWEEN :a AND :b",
                    [':a' => $start . ' 00:00:00', ':b' => $end . ' 23:59:59']
                );
            }),
            'odd_punch' => $safe(fn() => $this->db->value(
                "SELECT COUNT(*) FROM {$dash} WHERE OddPunch = 1 AND AttendanceDate BETWEEN :a AND :b",
                [':a' => $start, ':b' => $end . ' 23:59:59']
            )),
            'absent_today' => $safe(fn() => $this->db->value(
                "SELECT COUNT(*) FROM {$dash} WHERE Absent1 = 1 AND AttendanceDate BETWEEN :a AND :b",
                [':a' => $today, ':b' => $today . ' 23:59:59']
            )),
            'dayoff_today' => $safe(fn() => $this->db->value(
                "SELECT COUNT(*) FROM {$dtl} d JOIN {$shift} s ON s.ID = d.Shiftid
                  WHERE d.Deleted = 0 AND s.Name = 'DAY OFF'
                    AND d.ShiftDate BETWEEN :a AND :b",
                [':a' => $today, ':b' => $today . ' 23:59:59']
            )),
            // Late/early-today derivation (Atten_ vs schedule) — next iteration.
            'late_today' => 0,
            'early_today' => 0,
        ];
    }

    private function renderDashboard(string $period, array $counts): void
    {
        $tiles = [
            ['key' => 'shifts',          'label' => 'Duty Roster Master', 'icon' => 'grid',     'min' => 'dept_head'],
            ['key' => 'roster',          'label' => 'Duty Roster',        'icon' => 'calendar', 'min' => 'dept_head'],
            ['key' => 'roster/submit',   'label' => 'Submit Duty Roster', 'icon' => 'upload',   'min' => 'dept_head'],
            ['key' => 'approvals',       'label' => 'Approve Request',    'icon' => 'check',    'min' => 'dept_head'],
            ['key' => 'attendance',      'label' => 'View Attendance',    'icon' => 'clock',    'min' => 'employee'],
            ['key' => 'correction',      'label' => 'Attendance Correction','icon'=>'edit',     'min' => 'employee'],
            ['key' => 'schedule-change', 'label' => 'Change Schedule',    'icon' => 'swap',     'min' => 'employee'],
            ['key' => 'overtime',        'label' => 'Overtime',           'icon' => 'plus',     'min' => 'employee'],
            ['key' => 'employees',       'label' => 'Employees',          'icon' => 'users',    'min' => 'admin'],
            ['key' => 'departments',     'label' => 'Departments',        'icon' => 'building', 'min' => 'admin'],
        ];

        $this->view('dashboard/index', [
            'title'   => 'Main Dashboard',
            'period'  => $period,
            'counts'  => $counts,
            'tiles'   => $tiles,
        ]);
    }
}
