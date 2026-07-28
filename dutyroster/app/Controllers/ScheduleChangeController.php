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

        $rosterByDate = [];   // 'Y-m-d' => ['shift_id'=>, 'label'=>] for auto-filling Old Shift
        if (legacy_mode()) {
            $empRepo   = new \App\Repositories\EmployeeRepository($this->db);
            $emp       = $empId ? $empRepo->find($empId) : null;
            $requests  = ($empId && $emp)
                ? (new \App\Repositories\ScheduleChangeRepository($this->db))->forEmployee($empId)
                : [];
            $employees = Auth::atLeast('dept_head') ? $empRepo->search('') : [];
            $shifts    = (new \App\Repositories\ShiftRepository($this->db))->all();
            if ($empId && $emp) {
                $ws = date('Y-m-01', strtotime('-2 months'));
                $we = date('Y-m-t', strtotime('+2 months'));
                foreach ((new \App\Repositories\RosterRepository($this->db))->forEmployeeRange($empId, $ws, $we) as $d => $row) {
                    $rosterByDate[$d] = ['shift_id' => (int) $row['shift_id'], 'label' => shift_label($row)];
                }
            }
        } else {
            $emp = $empId ? $this->db->one("SELECT * FROM employees WHERE id=:id", [':id'=>$empId]) : null;
            $requests = $empId ? $this->db->all(
                $this->db->limit(
                    "SELECT sc.*, os.code AS old_code, ns.code AS new_code
                       FROM schedule_change_requests sc
                       LEFT JOIN shifts os ON os.id = sc.old_shift_id
                       LEFT JOIN shifts ns ON ns.id = sc.new_shift_id
                      WHERE sc.employee_id = :e ORDER BY sc.requested_at DESC", 50),
                [':e'=>$empId]
            ) : [];
            $employees = Auth::atLeast('dept_head')
                ? $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active=1 ORDER BY full_name") : [];
            $shifts = $this->db->all("SELECT * FROM shifts WHERE active=1 ORDER BY code");
        }

        $this->view('schedule_change/index', [
            'title'         => 'Change Schedule',
            'employees'     => $employees,
            'emp'           => $emp,
            'shifts'        => $shifts,
            'requests'      => $requests,
            'roster_by_date'=> $rosterByDate,
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

        $newShiftId = $this->input('new_shift_id');
        $payload = [
            'employee_id'         => $empId,
            'work_date'           => $date,
            'old_shift_id'        => $this->input('old_shift_id'),
            'new_shift_id'        => $newShiftId,
            'change_against_date' => $this->input('change_against_date'),
            'claim_time'          => $this->input('claim_time'),
        ];

        if (legacy_mode()) {
            // The old shift is whatever is currently rostered for that day — read
            // it from the roster (authoritative), which also guarantees the
            // NOT NULL ShiftID column is populated.
            $current = (new \App\Repositories\RosterRepository($this->db))
                ->forEmployeeRange($empId, $date, $date)[$date] ?? null;
            $oldShiftId = $current['shift_id'] ?? null;
            if (!$oldShiftId) {
                $this->flash('error', 'No shift is currently scheduled for that day, so there is nothing to change.');
                $this->redirect('schedule-change?employee_id=' . $empId);
            }
            if (!$newShiftId) {
                $this->flash('error', 'Please choose the new shift.');
                $this->redirect('schedule-change?employee_id=' . $empId);
            }
            $payload['old_shift_id'] = $oldShiftId;
            try {
                (new \App\Repositories\ScheduleChangeRepository($this->db))->create($payload);
                $this->flash('success', 'Schedule change request submitted.');
            } catch (\Throwable $e) {
                $this->flash('error', 'Could not save schedule change: ' . $e->getMessage());
            }
            $this->redirect('schedule-change?employee_id=' . $empId);
        }

        $this->db->insert('schedule_change_requests', [
            'employee_id'  => $empId,
            'period_key'   => period_of($date),
            'work_date'    => $date,
            'old_shift_id' => ($payload['old_shift_id'] ?? '') ?: null,
            'new_shift_id' => ($payload['new_shift_id'] ?? '') ?: null,
            'change_against_date' => ($payload['change_against_date'] ?? '') ?: null,
            'claim_time'   => ($payload['claim_time'] ?? '') ?: null,
            'status'       => 'pending',
            'requested_by' => Auth::id(),
        ]);
        $this->flash('success', 'Schedule change request submitted.');
        $this->redirect('schedule-change?employee_id=' . $empId);
    }
}
