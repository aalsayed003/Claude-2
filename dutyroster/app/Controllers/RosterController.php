<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class RosterController extends Controller
{
    /** Roster overview for a period. */
    public function index(): void
    {
        Auth::requireRole('dept_head');
        $period = $this->input('period', period_of(date('Y-m-d')));
        $rows = $this->db->all(
            "SELECT e.id, e.emp_id, e.full_name,
                    SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END) AS assigned_days
               FROM employees e
               LEFT JOIN roster r ON r.employee_id = e.id AND r.period_key = :p
              WHERE e.active = 1
              GROUP BY e.id, e.emp_id, e.full_name
              ORDER BY e.full_name",
            [':p' => $period]
        );
        $this->view('roster/index', [
            'title'  => 'Duty Roster',
            'period' => $period,
            'rows'   => $rows,
        ]);
    }

    /** Allot-shift screen for one employee / month. */
    public function allot(): void
    {
        Auth::requireRole('dept_head');
        $empId  = (int) $this->input('employee_id', 0);
        $period = $this->input('period', period_of(date('Y-m-d')));
        [$start, $end] = period_bounds($period);

        $emp = $empId ? $this->db->one("SELECT * FROM employees WHERE id = :id", [':id' => $empId]) : null;
        $shifts = $this->db->all("SELECT * FROM shifts WHERE active = 1 ORDER BY is_day_off DESC, code");

        $assigned = [];
        if ($emp) {
            foreach ($this->db->all(
                "SELECT r.work_date, r.shift_id, s.code, s.name, s.first_in, s.first_out,
                        s.second_in, s.second_out, s.total_hours
                   FROM roster r JOIN shifts s ON s.id = r.shift_id
                  WHERE r.employee_id = :e AND r.work_date BETWEEN :a AND :b",
                [':e' => $empId, ':a' => $start, ':b' => $end]
            ) as $r) {
                $assigned[$r['work_date']] = $r;
            }
        }

        $days = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts = strtotime('+1 day', $ts)) {
            $days[] = date('Y-m-d', $ts);
        }

        $this->view('roster/allot', [
            'title'    => 'Duty Roster — Allot Shift',
            'employees'=> $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active = 1 ORDER BY full_name"),
            'emp'      => $emp,
            'period'   => $period,
            'shifts'   => $shifts,
            'days'     => $days,
            'assigned' => $assigned,
            'scheduled_hours' => array_sum(array_map(fn($a) => (float) $a['total_hours'], $assigned)),
        ]);
    }

    /** Persist a month's allotment for one employee. */
    public function save(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $empId  = (int) $this->input('employee_id');
        $period = $this->input('period');
        $map    = $_POST['shift'] ?? [];   // [ 'YYYY-MM-DD' => shift_id ]

        if (!$empId || !$period) {
            $this->flash('error', 'Employee and period are required.');
            $this->redirect('roster');
        }

        $this->db->begin();
        try {
            foreach ($map as $date => $shiftId) {
                $shiftId = (int) $shiftId;
                if ($shiftId <= 0) {
                    $this->db->run(
                        "DELETE FROM roster WHERE employee_id = :e AND work_date = :d",
                        [':e' => $empId, ':d' => $date]
                    );
                    continue;
                }
                $exists = $this->db->one(
                    "SELECT id FROM roster WHERE employee_id = :e AND work_date = :d",
                    [':e' => $empId, ':d' => $date]
                );
                $row = [
                    'employee_id' => $empId,
                    'work_date'   => $date,
                    'shift_id'    => $shiftId,
                    'period_key'  => period_of($date),
                    'created_by'  => Auth::id(),
                ];
                if ($exists) {
                    $this->db->update('roster', $row, 'id = :id', [':id' => $exists['id']]);
                } else {
                    $this->db->insert('roster', $row);
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->flash('error', 'Save failed: ' . $e->getMessage());
            $this->redirect('roster/allot?employee_id=' . $empId . '&period=' . $period);
        }

        $this->flash('success', 'Roster saved for the period.');
        $this->redirect('roster/allot?employee_id=' . $empId . '&period=' . $period);
    }

    public function submitForm(): void
    {
        Auth::requireRole('dept_head');
        $period = $this->input('period', period_of(date('Y-m-d')));
        $this->view('roster/submit', [
            'title'  => 'Submit Duty Roster',
            'period' => $period,
            'depts'  => $this->db->all("SELECT * FROM departments ORDER BY name"),
            'sections' => $this->db->all("SELECT * FROM sections ORDER BY name"),
        ]);
    }

    public function submit(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $period = $this->input('period');
        $deptId = (int) $this->input('department_id');
        $sectionId = $this->input('section_id') ?: null;
        if (!$period || !$deptId) {
            $this->flash('error', 'Period and department are required.');
            $this->redirect('roster/submit');
        }
        $this->db->insert('roster_submissions', [
            'period_key'    => $period,
            'department_id' => $deptId,
            'section_id'    => $sectionId,
            'status'        => 'submitted',
            'submitted_by'  => Auth::id(),
            'submitted_at'  => date('Y-m-d H:i:s'),
        ]);
        $this->flash('success', 'Duty roster submitted for approval.');
        $this->redirect('approvals');
    }
}
