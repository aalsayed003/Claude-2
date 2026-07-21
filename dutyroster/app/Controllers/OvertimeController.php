<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class OvertimeController extends Controller
{
    public function index(): void
    {
        Auth::require();
        $user  = Auth::user();
        $empId = Auth::atLeast('dept_head')
            ? (int) $this->input('employee_id', $user['employee_id'] ?? 0)
            : (int) ($user['employee_id'] ?? 0);
        $period = $this->input('period', period_of(date('Y-m-d')));
        [$cutFrom, $cutTo] = period_bounds($period);

        $emp = $empId ? $this->db->one("SELECT * FROM employees WHERE id=:id", [':id'=>$empId]) : null;

        // Eligible OT from computed attendance (early-in / late-out beyond threshold).
        $eligible = $empId ? $this->db->all(
            "SELECT work_date, ot_early_min, ot_late_min FROM attendance
              WHERE employee_id = :e AND work_date BETWEEN :a AND :b
                AND (ot_early_min > 0 OR ot_late_min > 0)
              ORDER BY work_date",
            [':e'=>$empId, ':a'=>$cutFrom, ':b'=>$cutTo]
        ) : [];

        $requests = $empId ? $this->db->all(
            "SELECT * FROM overtime_requests WHERE employee_id = :e
              ORDER BY requested_at DESC LIMIT 50",
            [':e'=>$empId]
        ) : [];

        $this->view('overtime/index', [
            'title'     => 'Overtime',
            'employees' => Auth::atLeast('dept_head')
                ? $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active=1 ORDER BY full_name") : [],
            'emp'       => $emp,
            'period'    => $period,
            'eligible'  => $eligible,
            'requests'  => $requests,
        ]);
    }

    public function save(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId = (int) $this->input('employee_id');
        $date  = $this->input('ot_date');
        if (!$empId || !$date) {
            $this->flash('error', 'Employee and OT date are required.');
            $this->redirect('overtime');
        }
        $from = $this->input('from_time') ?: null;
        $to   = $this->input('to_time') ?: null;
        $mins = 0;
        if ($from && $to) {
            $a = strtotime($date . ' ' . $from);
            $b = strtotime($date . ' ' . $to);
            if ($b < $a) { $b += 86400; }
            $mins = (int) round(($b - $a) / 60);
        }
        $this->db->insert('overtime_requests', [
            'employee_id'  => $empId,
            'period_key'   => period_of($date),
            'ot_date'      => $date,
            'day_type'     => $this->input('day_type', 'working'),
            'from_time'    => $from,
            'to_time'      => $to,
            'total_minutes'=> $mins,
            'ot_type'      => $this->input('ot_type') ?: null,
            'is_split_day' => $this->input('is_split_day') ? 1 : 0,
            'reason'       => $this->input('reason') ?: null,
            'remark'       => $this->input('remark') ?: null,
            'status'       => 'pending',
            'requested_by' => Auth::id(),
        ]);
        $this->flash('success', 'Overtime request submitted.');
        $this->redirect('overtime?employee_id=' . $empId . '&period=' . period_of($date));
    }
}
