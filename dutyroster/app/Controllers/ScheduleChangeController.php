<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class ScheduleChangeController extends Controller
{
    public function index(): void
    {
        Auth::require();
        $user  = Auth::user();
        $empId = Auth::atLeast('dept_head')
            ? (int) $this->input('employee_id', $user['employee_id'] ?? 0)
            : (int) ($user['employee_id'] ?? 0);

        $emp = $empId ? $this->db->one("SELECT * FROM employees WHERE id=:id", [':id'=>$empId]) : null;
        $requests = $empId ? $this->db->all(
            "SELECT sc.*, os.code AS old_code, ns.code AS new_code
               FROM schedule_change_requests sc
               LEFT JOIN shifts os ON os.id = sc.old_shift_id
               LEFT JOIN shifts ns ON ns.id = sc.new_shift_id
              WHERE sc.employee_id = :e ORDER BY sc.requested_at DESC LIMIT 50",
            [':e'=>$empId]
        ) : [];

        $this->view('schedule_change/index', [
            'title'     => 'Change Schedule',
            'employees' => Auth::atLeast('dept_head')
                ? $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active=1 ORDER BY full_name") : [],
            'emp'       => $emp,
            'shifts'    => $this->db->all("SELECT * FROM shifts WHERE active=1 ORDER BY code"),
            'requests'  => $requests,
        ]);
    }

    public function save(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId = (int) $this->input('employee_id');
        $date  = $this->input('work_date');
        if (!$empId || !$date) {
            $this->flash('error', 'Employee and schedule day are required.');
            $this->redirect('schedule-change');
        }
        $this->db->insert('schedule_change_requests', [
            'employee_id'  => $empId,
            'period_key'   => period_of($date),
            'work_date'    => $date,
            'old_shift_id' => $this->input('old_shift_id') ?: null,
            'new_shift_id' => $this->input('new_shift_id') ?: null,
            'change_against_date' => $this->input('change_against_date') ?: null,
            'claim_time'   => $this->input('claim_time') ?: null,
            'status'       => 'pending',
            'requested_by' => Auth::id(),
        ]);
        $this->flash('success', 'Schedule change request submitted.');
        $this->redirect('schedule-change?employee_id=' . $empId);
    }
}
