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
        [$start, $end] = month_bounds($period);
        if (legacy_mode()) {
            $emps  = (new \App\Repositories\EmployeeRepository($this->db))->search('');
            $counts = (new \App\Repositories\RosterRepository($this->db))->assignedDaysByEmployee($start, $end);
            $rows = array_map(fn($e) => [
                'id' => $e['id'], 'emp_id' => $e['emp_id'], 'full_name' => $e['full_name'],
                'assigned_days' => $counts[$e['id']] ?? 0,
            ], $emps);
        } else {
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
        }
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
        [$start, $end] = month_bounds($period);

        if (legacy_mode()) {
            $empRepo = new \App\Repositories\EmployeeRepository($this->db);
            $emp     = $empId ? $empRepo->find($empId) : null;
            $shifts  = (new \App\Repositories\ShiftRepository($this->db))->all();
            $assigned = $emp
                ? (new \App\Repositories\RosterRepository($this->db))->forEmployeeRange($empId, $start, $end)
                : [];
            $employees = $empRepo->search('');
        } else {
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
            $employees = $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active = 1 ORDER BY full_name");
        }

        $days = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts = strtotime('+1 day', $ts)) {
            $days[] = date('Y-m-d', $ts);
        }

        $this->view('roster/allot', [
            'title'    => 'Duty Roster — Allot Shift',
            'employees'=> $employees,
            'emp'      => $emp,
            'period'   => $period,
            'shifts'   => $shifts,
            'days'     => $days,
            'assigned' => $assigned,
            'scheduled_hours' => array_sum(array_map(fn($a) => (float) $a['total_hours'], $assigned)),
        ]);
    }

    /** Persist a month's allotment for one employee, then auto-submit it for approval. */
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

        $submitted = false;
        $this->db->begin();
        try {
            if (legacy_mode()) {
                $this->saveLegacy($empId, $period, $map);
                $submitted = $this->ensureLegacySubmission($empId, $period);
            } else {
                $this->saveNative($empId, $map);
                $submitted = $this->ensureNativeSubmission($empId, $period);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            $this->flash('error', 'Save failed: ' . $e->getMessage());
            $this->redirect('roster/allot?employee_id=' . $empId . '&period=' . $period);
        }

        $this->flash('success', $submitted
            ? 'Roster saved and submitted for approval.'
            : 'Roster saved (already submitted and awaiting approval for this month).');
        $this->redirect('roster/allot?employee_id=' . $empId . '&period=' . $period);
    }

    /**
     * Insert into a legacy table whose primary key may or may not be an
     * IDENTITY column. TestASSH was populated with SELECT INTO from the source
     * databases, and SELECT INTO does NOT carry over the IDENTITY property — so
     * tables such as Schedule_Request / AllotShift can end up with a plain
     * (non-identity) PK that rejects a NULL insert (this is exactly the
     * "Cannot insert the value NULL into column 'RequestID'" failure we already
     * hit on DR_CorrectionRequest). When the PK is not identity we supply
     * MAX(id)+1 ourselves; when it is, we let the engine assign it. Returns the
     * PK value in both cases.
     */
    private function insertLegacy(string $table, array $data, string $idCol = 'ID'): int
    {
        if (!array_key_exists($idCol, $data) && !$this->db->isIdentity($table, $idCol)) {
            $id = $this->db->nextId($table, $idCol);
            $this->db->insert($table, [$idCol => $id] + $data);
            return $id;
        }
        return $this->db->insert($table, $data);
    }

    /**
     * Auto-create a legacy Schedule_Request (Approved = 1 = Submitted,
     * Uploaded = 0) for the employee's department/month, right after saving
     * the roster — unless one is already in progress (Uploaded = 0), in
     * which case it's left alone so an in-progress approval isn't reset to
     * step 1 every time the roster is re-saved. Returns true if a new
     * submission row was created.
     */
    private function ensureLegacySubmission(int $empId, string $period): bool
    {
        $emp = (new \App\Repositories\EmployeeRepository($this->db))->find($empId);
        $deptId = $emp['department_id'] ?? null;
        if (!$deptId) {
            return false; // employee has no department on file — can't route for approval
        }

        $t = lt('sched_req'); // Schedule_Request
        [$y, $m] = array_map('intval', explode('-', $period));
        $monthStart = sprintf('%04d-%02d-01', $y, $m);

        $existing = $this->db->one(
            "SELECT ID FROM {$t} WHERE DepartmentId = :d AND ScheduleMonth = :m AND Uploaded = 0",
            [':d' => $deptId, ':m' => $monthStart]
        );
        if ($existing) {
            return false;
        }

        $this->insertLegacy($t, [
            'DateTime'      => date('Y-m-d H:i:s'),
            'DepartmentId'  => $deptId,
            'ScheduleMonth' => $monthStart,
            'OperatorId'    => Auth::id(),
            'Approved'      => 1,   // 1 = Submitted (legacy.schedule_states)
            'Comments'      => null,
            'Uploaded'      => 0,
            'Modify'        => 0,
            'Reason'        => null,
        ]);
        return true;
    }

    /** Same idea as ensureLegacySubmission(), for the clean schema. */
    private function ensureNativeSubmission(int $empId, string $period): bool
    {
        $emp = $this->db->one("SELECT department_id FROM employees WHERE id = :id", [':id' => $empId]);
        $deptId = $emp['department_id'] ?? null;
        if (!$deptId) {
            return false;
        }

        $existing = $this->db->one(
            "SELECT id FROM roster_submissions
              WHERE department_id = :d AND period_key = :p AND status NOT IN ('approved', 'rejected')",
            [':d' => $deptId, ':p' => $period]
        );
        if ($existing) {
            return false;
        }

        $this->db->insert('roster_submissions', [
            'period_key'    => $period,
            'department_id' => $deptId,
            'status'        => 'submitted',
            'submitted_by'  => Auth::id(),
            'submitted_at'  => date('Y-m-d H:i:s'),
        ]);
        return true;
    }

    /** Save into the app's own clean schema (non-legacy mode only). */
    private function saveNative(int $empId, array $map): void
    {
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
    }

    /**
     * Save into the legacy AllotShift (header, one row per employee/month)
     * + AllotShiftDetail (one row per day, keyed by ShiftDate) tables — the
     * FINAL uploaded roster (as opposed to Allot_Shift / Allot_ShiftDetails,
     * the DRAFT pending-approval roster; see config 'roster_draft_hdr' /
     * 'roster_draft_dtl' for that separate workflow).
     */
    private function saveLegacy(int $empId, string $period, array $map): void
    {
        $hdr = lt('roster_hdr');   // AllotShift
        $dtl = lt('roster_dtl');   // AllotShiftDetail
        [$y, $m] = array_map('intval', explode('-', $period));
        $monthStart = sprintf('%04d-%02d-01', $y, $m);

        $header = $this->db->one(
            "SELECT ID FROM {$hdr} WHERE Empid = :e AND CurrentMonth = :m AND Deleted = 0",
            [':e' => $empId, ':m' => $monthStart]
        );
        $allotId = $header
            ? (int) $header['ID']
            : $this->insertLegacy($hdr, [
                'Empid'        => $empId,
                'CurrentMonth' => $monthStart,
                'Deleted'      => 0,
                'operatorid'   => Auth::id(),
                'TotalHours'   => 0,
            ]);

        $byId = [];
        foreach ((new \App\Repositories\ShiftRepository($this->db))->all() as $s) {
            $byId[(int) $s['id']] = $s;
        }

        $totalHours = 0.0;
        foreach ($map as $date => $shiftId) {
            $shiftId = (int) $shiftId;
            $existing = $this->db->one(
                "SELECT AllotId FROM {$dtl} WHERE AllotId = :a AND ShiftDate = :d",
                [':a' => $allotId, ':d' => $date]
            );
            if ($shiftId <= 0) {
                if ($existing) {
                    $this->db->run(
                        "DELETE FROM {$dtl} WHERE AllotId = :a AND ShiftDate = :d",
                        [':a' => $allotId, ':d' => $date]
                    );
                }
                continue;
            }
            $hours = (float) ($byId[$shiftId]['total_hours'] ?? 0);
            $totalHours += $hours;
            if ($existing) {
                $this->db->run(
                    "UPDATE {$dtl} SET Shiftid = :s, ShiftDay = :wd, Deleted = 0, TotalHours = :h
                      WHERE AllotId = :a AND ShiftDate = :d",
                    [':s' => $shiftId, ':wd' => date('l', strtotime($date)),
                     ':h' => $hours, ':a' => $allotId, ':d' => $date]
                );
            } else {
                $this->db->insert($dtl, [
                    'AllotId'    => $allotId,
                    'Shiftid'    => $shiftId,
                    'ShiftDay'   => date('l', strtotime($date)),
                    'ShiftDate'  => $date,
                    'Deleted'    => 0,
                    'TotalHours' => $hours,
                ]);
            }
        }

        $this->db->run(
            "UPDATE {$hdr} SET TotalHours = :h WHERE ID = :id",
            [':h' => $totalHours, ':id' => $allotId]
        );
    }

    public function submitForm(): void
    {
        Auth::requireRole('dept_head');
        $period = $this->input('period', period_of(date('Y-m-d')));
        if (legacy_mode()) {
            $depts    = (new \App\Repositories\DepartmentRepository($this->db))->all();
            $sections = [];   // no distinct legacy section master; department is the unit here
        } else {
            $depts    = $this->db->all("SELECT * FROM departments ORDER BY name");
            $sections = $this->db->all("SELECT * FROM sections ORDER BY name");
        }
        $this->view('roster/submit', [
            'title'  => 'Submit Duty Roster',
            'period' => $period,
            'depts'  => $depts,
            'sections' => $sections,
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

        if (legacy_mode()) {
            [$y, $m] = array_map('intval', explode('-', $period));
            $t = lt('sched_req');   // Schedule_Request
            $this->insertLegacy($t, [
                'DateTime'      => date('Y-m-d H:i:s'),
                'DepartmentId'  => $deptId,
                'ScheduleMonth' => sprintf('%04d-%02d-01', $y, $m),
                'OperatorId'    => Auth::id(),
                'Approved'      => 1,   // 1 = Submitted (legacy.schedule_states)
                'Comments'      => null,
                'Uploaded'      => 0,
                'Modify'        => 0,
                'Reason'        => null,
            ]);
        } else {
            $this->db->insert('roster_submissions', [
                'period_key'    => $period,
                'department_id' => $deptId,
                'section_id'    => $sectionId,
                'status'        => 'submitted',
                'submitted_by'  => Auth::id(),
                'submitted_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        $this->flash('success', 'Duty roster submitted for approval.');
        $this->redirect('approvals');
    }
}