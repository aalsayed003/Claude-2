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

        // Today's attendance counts (odd punch / absent / day-off / late / early)
        // are derived from the SAME live punch pairing as View Attendance, so the
        // dashboard can't disagree with the attendance page or silently read a
        // stale pre-computed DRMainDashBoard table. Computed once for the day,
        // guarded so a data gap can't break the dashboard.
        try {
            $todayCounts = \App\Services\DashboardMetrics::todayCounts($this->db, $today);
        } catch (\Throwable $e) {
            $todayCounts = ['absent' => 0, 'odd_punch' => 0, 'late' => 0, 'early' => 0, 'day_off' => 0];
        }

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
            'odd_punch'    => (int) ($todayCounts['odd_punch'] ?? 0),
            'absent_today' => (int) ($todayCounts['absent'] ?? 0),
            'dayoff_today' => (int) ($todayCounts['day_off'] ?? 0),
            'late_today'   => (int) ($todayCounts['late'] ?? 0),
            'early_today'  => (int) ($todayCounts['early'] ?? 0),
        ];
    }

    private function renderDashboard(string $period, array $counts): void
    {
        $tiles = [
            ['key' => 'shifts',          'label' => 'Duty Roster Master', 'icon' => 'grid',     'min' => 'dept_head'],
            ['key' => 'roster',          'label' => 'Duty Roster',        'icon' => 'calendar', 'min' => 'dept_head'],
            ['key' => 'approvals',       'label' => 'Approve Request',    'icon' => 'check',    'min' => 'dept_head'],
            ['key' => 'attendance',      'label' => 'Attendance',         'icon' => 'clock',    'min' => 'employee'],
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
