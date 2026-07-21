<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\AttendanceEngine;

class AttendanceController extends Controller
{
    public function index(): void
    {
        Auth::require();
        $user = Auth::user();

        // Employees can only view themselves; dept_head+ can pick anyone.
        $canPickAnyone = Auth::atLeast('dept_head');
        $empId = (int) $this->input('employee_id', 0);
        if (!$canPickAnyone) {
            $empId = (int) ($user['employee_id'] ?? 0);
        }

        $from = $this->input('from');
        $to   = $this->input('to');
        if (!$from || !$to) {
            [$from, $to] = period_bounds(period_of(date('Y-m-d')));
        }

        $rows = [];
        $emp  = null;
        if ($empId) {
            $emp = $this->db->one("SELECT * FROM employees WHERE id = :id", [':id' => $empId]);
            $rows = $this->db->all(
                "SELECT a.*, s.code AS shift_code, s.first_in AS sch_first_in,
                        s.first_out AS sch_first_out, s.second_in AS sch_second_in,
                        s.second_out AS sch_second_out
                   FROM attendance a
                   LEFT JOIN shifts s ON s.id = a.shift_id
                  WHERE a.employee_id = :e AND a.work_date BETWEEN :a AND :b
                  ORDER BY a.work_date",
                [':e' => $empId, ':a' => $from, ':b' => $to]
            );
        }

        $this->view('attendance/index', [
            'title'         => 'View Attendance',
            'employees'     => $canPickAnyone
                ? $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active = 1 ORDER BY full_name")
                : [],
            'canPickAnyone' => $canPickAnyone,
            'emp'           => $emp,
            'rows'          => $rows,
            'from'          => $from,
            'to'            => $to,
        ]);
    }

    public function rebuild(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $period = $this->input('period', period_of(date('Y-m-d')));
        $engine = new AttendanceEngine($this->db);
        $n = $engine->rebuildPeriod($period);
        $this->flash('success', "Attendance recomputed for {$n} employee-days in " . period_label($period) . '.');
        $this->redirect('attendance');
    }
}
