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
                "SELECT COUNT(*) FROM attendance
                  WHERE period_key = :p AND is_odd_punch = 1",
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
