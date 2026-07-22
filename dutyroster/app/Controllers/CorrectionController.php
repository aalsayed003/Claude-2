<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class CorrectionController extends Controller
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
        $attendance = $empId ? $this->db->all(
            "SELECT a.*, s.code AS shift_code FROM attendance a
               LEFT JOIN shifts s ON s.id = a.shift_id
              WHERE a.employee_id = :e AND a.work_date BETWEEN :a AND :b
              ORDER BY a.work_date",
            [':e'=>$empId, ':a'=>$cutFrom, ':b'=>$cutTo]
        ) : [];

        $requests = $empId ? $this->db->all(
            $this->db->limit(
                "SELECT cr.*, (SELECT COUNT(*) FROM correction_details cd WHERE cd.request_id=cr.id) AS lines
                   FROM correction_requests cr
                  WHERE cr.employee_id = :e ORDER BY cr.requested_at DESC", 50),
            [':e'=>$empId]
        ) : [];

        $this->view('correction/index', [
            'title'      => 'Attendance Correction',
            'employees'  => Auth::atLeast('dept_head')
                ? $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active=1 ORDER BY full_name") : [],
            'emp'        => $emp,
            'period'     => $period,
            'cutFrom'    => $cutFrom,
            'cutTo'      => $cutTo,
            'attendance' => $attendance,
            'requests'   => $requests,
        ]);
    }

    public function save(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId  = (int) $this->input('employee_id');
        $period = $this->input('period');
        $date   = $this->input('work_date');
        if (!$empId || !$date) {
            $this->flash('error', 'Employee and date are required.');
            $this->redirect('correction');
        }
        $reqId = $this->db->insert('correction_requests', [
            'employee_id' => $empId,
            'period_key'  => $period,
            'status'      => 'pending',
            'requested_by'=> Auth::id(),
        ]);
        $this->db->insert('correction_details', [
            'request_id' => $reqId,
            'work_date'  => $date,
            'first_in'   => $this->input('first_in') ?: null,
            'first_out'  => $this->input('first_out') ?: null,
            'second_in'  => $this->input('second_in') ?: null,
            'second_out' => $this->input('second_out') ?: null,
            'reason'     => $this->input('reason') ?: null,
            'remarks'    => $this->input('remarks') ?: null,
        ]);
        $this->flash('success', 'Correction request submitted.');
        $this->redirect('correction?employee_id=' . $empId . '&period=' . $period);
    }
}
