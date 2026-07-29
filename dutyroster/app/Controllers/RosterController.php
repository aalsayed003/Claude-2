<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Services\XlsxWriter;
use App\Services\SpreadsheetReader;
use App\Repositories\EmployeeRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\RosterRepository;

class RosterController extends Controller
{
    /** Merged Roster workspace: employee list + Allot Shift grid + Bulk Excel Import, as tabs on one page. */
    public function index(): void
    {
        Auth::requireRole('dept_head');
        $period = $this->input('period', period_of(date('Y-m-d')));
        $empId  = (int) $this->input('employee_id', 0);
        $tab    = $this->input('tab', 'roster');
        $this->renderWorkspace($tab, $period, $empId, null);
    }

    /**
     * Builds and renders the combined Roster workspace: the employee list +
     * Allot Shift grid on the "roster" tab, and the Bulk Excel Import form
     * (+ optional import result) on the "bulk" tab. Called by index() for a
     * normal page view, and by import()/submitForm() so the bulk-import
     * result renders back on the same merged page.
     */
    private function renderWorkspace(string $tab, string $period, int $empId, ?array $import): void
    {
        [$start, $end] = month_bounds($period);

        // --- Employees + roster list (assigned-day counts) ---
        if (legacy_mode()) {
            $empRepo   = new EmployeeRepository($this->db);
            $employees = $empRepo->search('');
            $counts    = (new RosterRepository($this->db))->assignedDaysByEmployee($start, $end);
            $rows = array_map(fn($e) => [
                'id' => $e['id'], 'emp_id' => $e['emp_id'], 'full_name' => $e['full_name'],
                'assigned_days' => $counts[$e['id']] ?? 0,
            ], $employees);
        } else {
            $employees = $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active = 1 ORDER BY full_name");
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

        // --- Allot Shift grid (only populated once an employee is picked) ---
        $emp = null; $shifts = []; $assigned = []; $lastByDom = [];
        if (legacy_mode()) {
            $emp    = $empId ? (new EmployeeRepository($this->db))->find($empId) : null;
            $shifts = (new ShiftRepository($this->db))->all();
            $assigned = $emp ? (new RosterRepository($this->db))->forEmployeeRange($empId, $start, $end) : [];
        } else {
            $emp = $empId ? $this->db->one("SELECT * FROM employees WHERE id = :id", [':id' => $empId]) : null;
            $shifts = $this->db->all("SELECT * FROM shifts WHERE active = 1 ORDER BY is_day_off DESC, code");
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
        }

        $days = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts = strtotime('+1 day', $ts)) {
            $days[] = date('Y-m-d', $ts);
        }

        // Last month's roster (day-of-month => shift_id) for the in-app "roll forward".
        if ($emp) {
            [$ls, $le] = month_bounds(date('Y-m', strtotime($start . ' -1 month')));
            if (legacy_mode()) {
                foreach ((new RosterRepository($this->db))->forEmployeeRange($empId, $ls, $le) as $ld => $la) {
                    $lastByDom[(int) date('j', strtotime($ld))] = (int) $la['shift_id'];
                }
            } else {
                foreach ($this->db->all(
                    "SELECT work_date, shift_id FROM roster WHERE employee_id = :e AND work_date BETWEEN :a AND :b",
                    [':e' => $empId, ':a' => $ls, ':b' => $le]
                ) as $r) {
                    $lastByDom[(int) date('j', strtotime($r['work_date']))] = (int) $r['shift_id'];
                }
            }
        }

        // --- Bulk Excel Import tab ---
        if (legacy_mode()) {
            $depts    = (new DepartmentRepository($this->db))->all();
            $sections = [];
        } else {
            $depts    = $this->db->all("SELECT * FROM departments ORDER BY name");
            $sections = $this->db->all("SELECT * FROM sections ORDER BY name");
        }

        $this->view('roster/workspace', [
            'title'    => 'Duty Roster',
            'tab'      => in_array($tab, ['roster', 'bulk'], true) ? $tab : 'roster',
            'period'   => $period,
            'rows'     => $rows,
            'not_scheduled' => count(array_filter($rows, fn($r) => (int) $r['assigned_days'] === 0)),
            'total_employees' => count($rows),
            'employees'=> $employees,
            'emp'      => $emp,
            'shifts'   => $shifts,
            'days'     => $days,
            'assigned' => $assigned,
            'last_by_dom' => $lastByDom,
            'scheduled_hours' => array_sum(array_map(fn($a) => (float) $a['total_hours'], $assigned)),
            'depts'    => $depts,
            'sections' => $sections,
            'import'   => $import,
        ]);
    }

    /** Allot Shift is now the "Roster" tab on the merged workspace page. */
    public function allot(): void
    {
        Auth::requireRole('dept_head');
        $qs = $_GET;
        $qs['tab'] = 'roster';
        $this->redirect('roster?' . http_build_query($qs));
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
            $this->redirect('roster?tab=roster&employee_id=' . $empId . '&period=' . $period);
        }

        $this->flash('success', $submitted
            ? 'Roster saved and submitted for approval.'
            : 'Roster saved (already submitted and awaiting approval for this month).');
        $this->redirect('roster?tab=roster&employee_id=' . $empId . '&period=' . $period);
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
        return $this->ensureLegacySubmissionByDept((int) $deptId, $period);
    }

    /** Dept-level variant used by both the per-employee save and the bulk import. */
    private function ensureLegacySubmissionByDept(int $deptId, string $period): bool
    {
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

        // Recompute the header total from ALL current detail rows, not just the
        // ones in this call — an Excel import only carries the days it changed.
        $sum = (float) $this->db->value(
            "SELECT COALESCE(SUM(TotalHours), 0) FROM {$dtl} WHERE AllotId = :a AND Deleted = 0",
            [':a' => $allotId]
        );
        $this->db->run(
            "UPDATE {$hdr} SET TotalHours = :h WHERE ID = :id",
            [':h' => $sum, ':id' => $allotId]
        );
    }

    /** The old standalone "Submit Duty Roster" page is now the "Bulk Import" tab on the merged workspace. */
    public function submitForm(): void
    {
        Auth::requireRole('dept_head');
        $qs = $_GET;
        $qs['tab'] = 'bulk';
        $this->redirect('roster?' . http_build_query($qs));
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
            $this->redirect('roster?tab=bulk');
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

    // === Excel template + bulk upload ==================================

    /**
     * Human-friendly dropdown label for a shift, e.g. "MORNING (08:00–16:00 · 8h)".
     * Day Off / Public Holiday and any timeless shift fall back to just the code.
     * Used for BOTH the template dropdown/pre-fill and the import lookup, so the
     * two always agree on the exact string.
     */
    private function shiftLabel(array $s): string
    {
        return shift_label($s);
    }

    /**
     * Stream a ready-to-fill .xlsx duty-roster template for one department and
     * month. Rows = the department's employees (ID + name locked-looking), one
     * column per calendar day, every day-cell a dropdown of the valid shift
     * codes. Any existing roster for the month is pre-filled so the file can be
     * edited and re-uploaded. A hidden Meta sheet records the dept + month so
     * the upload knows exactly what it's importing.
     */
    public function template(): void
    {
        Auth::requireRole('dept_head');
        $deptId = (int) $this->input('department_id', 0);
        $period = $this->input('period', period_of(date('Y-m-d')));
        if (!$deptId) {
            $this->flash('error', 'Choose a department before downloading the template.');
            $this->redirect('roster?tab=bulk&period=' . $period);
        }

        $dept      = (new DepartmentRepository($this->db))->find($deptId);
        $deptName  = $dept['name'] ?? ('Department ' . $deptId);
        $employees = (new EmployeeRepository($this->db))->byDepartment($deptId);
        $shifts    = (new ShiftRepository($this->db))->all();
        $roster    = new RosterRepository($this->db);

        $labels = [];      // dropdown values, e.g. "MORNING (08:00–16:00 · 8h)"
        $labelById = [];   // shift id => label, for pre-filling existing roster cells
        foreach ($shifts as $s) {
            $lbl = $this->shiftLabel($s);
            if ($lbl === '') continue;
            $labelById[(int) $s['id']] = $lbl;
            if (!in_array($lbl, $labels, true)) $labels[] = $lbl;
        }

        [$start, $end] = month_bounds($period);
        $days = [];
        for ($ts = strtotime($start); $ts <= strtotime($end); $ts = strtotime('+1 day', $ts)) {
            $days[] = date('Y-m-d', $ts);
        }

        $xlsx = new XlsxWriter();
        $R = $xlsx->addSheet('Roster');
        $L = $xlsx->addSheet('Lists', true);
        $M = $xlsx->addSheet('Meta', true);

        $lastCol      = 2 + count($days);
        $lastColL     = XlsxWriter::colLetter($lastCol);
        $headerRow    = 4;
        $firstDataRow = $headerRow + 1;

        $xlsx->setCols($R, [[1, 1, 12], [2, 2, 34], [3, $lastCol, 6]]);
        $xlsx->setFreeze($R, 2, $headerRow);

        $xlsx->addMerge($R, 'A1:' . $lastColL . '1');
        $xlsx->addMerge($R, 'A2:' . $lastColL . '2');
        $xlsx->setCell($R, 1, 1, 'DUTY ROSTER   —   ' . $deptName . '   —   ' . period_label($period), 1);
        $xlsx->setCell($R, 2, 1,
            'Pick a shift for each day from the dropdown. Leave a day blank to keep its current '
            . 'schedule (not updated). Do NOT change the Employee ID / Name columns or the day headings.', 0);

        $xlsx->setCell($R, $headerRow, 1, 'Employee ID', 2);
        $xlsx->setCell($R, $headerRow, 2, 'Employee Name', 2);
        foreach ($days as $k => $d) {
            $xlsx->setCell($R, $headerRow, 3 + $k,
                sprintf('%02d %s', (int) date('j', strtotime($d)), date('D', strtotime($d))), 2);
        }

        $prefill = (bool) $this->input('prefill', 0);   // roll forward from last month
        $row = $firstDataRow;
        foreach ($employees as $emp) {
            $vals = $this->templateRowValues((int) $emp['id'], $days, $start, $end, $labelById, $prefill);
            $xlsx->setCell($R, $row, 1, $emp['emp_id'], 5, 's');
            $xlsx->setCell($R, $row, 2, $emp['full_name'], 5, 's');
            foreach ($days as $k => $d) {
                $xlsx->setCell($R, $row, 3 + $k, $vals[$d], 4, 's');
            }
            $row++;
        }
        $lastDataRow = $row - 1;

        if ($employees && $labels) {
            $xlsx->addValidationList(
                $R,
                'C' . $firstDataRow . ':' . $lastColL . $lastDataRow,
                'Lists!$A$2:$A$' . (count($labels) + 1)
            );
        }

        $xlsx->setCell($L, 1, 1, 'Shifts', 2);
        foreach ($labels as $i => $c) {
            $xlsx->setCell($L, 2 + $i, 1, $c, 0, 's');
        }

        $xlsx->setCell($M, 1, 1, 'DepartmentId');   $xlsx->setCell($M, 1, 2, $deptId, 0, 'n');
        $xlsx->setCell($M, 2, 1, 'DepartmentName'); $xlsx->setCell($M, 2, 2, $deptName, 0, 's');
        $xlsx->setCell($M, 3, 1, 'Period');         $xlsx->setCell($M, 3, 2, $period, 0, 's');
        $xlsx->setCell($M, 4, 1, 'Version');        $xlsx->setCell($M, 4, 2, 1, 0, 'n');

        $safe = preg_replace('/[^A-Za-z0-9]+/', '_', $deptName);
        $xlsx->stream('DutyRoster_' . trim($safe, '_') . '_' . $period . '.xlsx');
    }

    /**
     * The cell value (shift label) for each day of one employee's template row.
     * With $prefill, empty days are seeded from the PREVIOUS month's roster
     * mapped by day-of-month (1st→1st, 2nd→2nd, …) — "roll forward" — so a team
     * leader can start from last month and just edit the changes. This month's
     * own roster always wins over the rolled-forward value.
     *
     * @return array<string,string>  'Y-m-d' => label ('' for a blank day)
     */
    private function templateRowValues(int $empId, array $days, string $start, string $end, array $labelById, bool $prefill): array
    {
        $roster   = new RosterRepository($this->db);
        $assigned = $roster->forEmployeeRange($empId, $start, $end);

        $lastByDom = [];   // day-of-month => label, from the previous month
        if ($prefill) {
            [$lastStart, $lastEnd] = month_bounds(date('Y-m', strtotime($start . ' -1 month')));
            foreach ($roster->forEmployeeRange($empId, $lastStart, $lastEnd) as $ld => $la) {
                $lastByDom[(int) date('j', strtotime($ld))] =
                    $labelById[(int) $la['shift_id']] ?? trim((string) $la['code']);
            }
        }

        $out = [];
        foreach ($days as $d) {
            $a = $assigned[$d] ?? null;
            if ($a) {
                $out[$d] = $labelById[(int) $a['shift_id']] ?? trim((string) $a['code']);
            } elseif ($prefill) {
                $out[$d] = $lastByDom[(int) date('j', strtotime($d))] ?? '';
            } else {
                $out[$d] = '';
            }
        }
        return $out;
    }

    /**
     * Receive a filled-in template, validate every row/cell (unknown employee
     * IDs, bad day headings, unknown shift codes, duplicates, wrong dept/month),
     * and — only if there are zero blocking errors — write the whole department's
     * month into AllotShift/AllotShiftDetail and raise one Schedule_Request. On
     * any error nothing is written and a cell-referenced report is shown.
     */
    public function import(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();

        $formDept   = (int) $this->input('department_id', 0);
        $formPeriod = $this->input('period', period_of(date('Y-m-d')));

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Please choose a filled-in roster file to upload.');
            $this->redirect('roster?tab=bulk&period=' . $formPeriod . ($formDept ? '&department_id=' . $formDept : ''));
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            $this->flash('error', 'Unsupported file type. Upload the .xlsx template (or a .csv).');
            $this->redirect('roster?tab=bulk&period=' . $formPeriod);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dr_imp_');
        move_uploaded_file($file['tmp_name'], $tmp);

        try {
            $result = $this->parseRosterFile($tmp, $ext, $formDept, $formPeriod);
            if ($result['ok']) {
                $this->applyRosterImport($result);
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->renderWorkspace('bulk', $formPeriod, 0, [
                'ok' => false, 'errors' => ['Could not process the file: ' . $e->getMessage()],
                'warnings' => [], 'summary' => null, 'deptName' => '', 'period' => $formPeriod,
            ]);
            return;
        }
        @unlink($tmp);

        $this->renderWorkspace('bulk', $result['period'] ?: $formPeriod, 0, $result);
    }

    /** Parse + validate an uploaded roster file. Never writes to the DB. */
    private function parseRosterFile(string $path, string $ext, int $formDept, string $formPeriod): array
    {
        $errors = $warnings = [];

        // Trust the hidden Meta sheet (it matches the grid) when present.
        $deptId = $formDept; $period = $formPeriod; $deptName = '';
        if ($ext === 'xlsx') {
            $meta = [];
            foreach (SpreadsheetReader::namedMatrix($path, 'Meta') as $r) {
                if (isset($r[0])) $meta[trim((string) $r[0])] = trim((string) ($r[1] ?? ''));
            }
            if (!empty($meta['DepartmentId'])) $deptId  = (int) $meta['DepartmentId'];
            if (!empty($meta['Period']))       $period  = $meta['Period'];
            $deptName = $meta['DepartmentName'] ?? '';
            if ($formDept && $deptId && $formDept !== $deptId) {
                $warnings[] = 'This file was generated for ' . ($deptName ?: 'another department')
                    . '; importing it under that department, not the one selected.';
            }
        }
        if (!$deptId) {
            return $this->parseFail('Select the department and month this file is for before uploading.', $period);
        }
        if ($deptName === '') {
            $d = (new DepartmentRepository($this->db))->find($deptId);
            $deptName = $d['name'] ?? ('Department ' . $deptId);
        }

        // Keyed by true spreadsheet row number so error messages cite real cells.
        $matrix = SpreadsheetReader::matrixIndexed($path, $ext);
        if (!$matrix) {
            return $this->parseFail('The file appears to be empty.', $period);
        }

        // Locate the header row (its first cell reads like "Employee ID").
        $headerRowNo = null; $header = null;
        foreach ($matrix as $rowNo => $r) {
            $norm = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($r[0] ?? '')));
            if ($norm === 'employeeid') { $headerRowNo = $rowNo; $header = $r; break; }
        }
        if ($headerRowNo === null) {
            return $this->parseFail('Could not find the header row (a cell reading "Employee ID"). '
                . 'Please upload the generated template without deleting its heading rows.', $period);
        }

        // Map each day column to a real date in the period.
        [$y, $mo] = array_map('intval', explode('-', $period));
        $dayCount = (int) date('t', mktime(0, 0, 0, $mo, 1, $y));
        $colDate  = [];   // matrix col index => 'Y-m-d'
        for ($c = 2; $c < count($header); $c++) {
            $txt = trim((string) $header[$c]);
            if ($txt === '') continue;
            // Excel sometimes turns a heading cell into a real date (e.g. an autofill drag
            // over the header row) — that stores a raw serial day-count instead of the "DD Ddd"
            // text, so decode it before falling back to the plain digit match below.
            if (preg_match('/^\d+(?:\.\d+)?$/', $txt) && (float) $txt > 1000) {
                $ts = (int) round(((float) $txt - 25569) * 86400);
                if ((int) gmdate('n', $ts) === $mo && (int) gmdate('Y', $ts) === $y) {
                    $colDate[$c] = sprintf('%04d-%02d-%02d', $y, $mo, (int) gmdate('j', $ts));
                    continue;
                }
            }
            if (preg_match('/(\d{1,2})/', $txt, $m) && (int) $m[1] >= 1 && (int) $m[1] <= $dayCount) {
                $colDate[$c] = sprintf('%04d-%02d-%02d', $y, $mo, (int) $m[1]);
            } else {
                $warnings[] = 'Ignored an unrecognised day heading "' . $txt . '".';
            }
        }
        if (!$colDate) {
            return $this->parseFail('No day columns were found in the sheet.', $period, $warnings);
        }

        $empByCode  = (new EmployeeRepository($this->db))->codeMap();
        // Accept either the descriptive dropdown label or the bare shift code,
        // case-insensitively (so older templates and manual entries still work).
        $shiftLookup = [];
        foreach ((new ShiftRepository($this->db))->all() as $s) {
            $id = (int) $s['id'];
            $shiftLookup[strtoupper(trim((string) $s['code']))] = $id;
            $lbl = $this->shiftLabel($s);
            if ($lbl !== '') $shiftLookup[strtoupper(trim($lbl))] = $id;
        }

        $employees = [];
        $seen = [];
        foreach ($matrix as $rowNo => $r) {
            if ($rowNo <= $headerRowNo) continue;   // skip title/instructions/header
            $code = trim((string) ($r[0] ?? ''));
            if ($code === '') continue;
            $excelRow = $rowNo;

            if (!isset($empByCode[$code])) {
                $errors[] = "Row {$excelRow}: unknown Employee ID \"{$code}\".";
                continue;
            }
            if (isset($seen[$code])) {
                $errors[] = "Row {$excelRow}: Employee ID \"{$code}\" appears more than once.";
                continue;
            }
            $seen[$code] = true;

            if (($empByCode[$code]['department_id'] ?? null) !== $deptId) {
                $warnings[] = "Row {$excelRow}: {$code} is on file under a different department.";
            }

            // Non-destructive: a blank cell means "schedule not updated" (leave
            // that day exactly as it is); an unrecognised value is skipped with a
            // note. Only recognised shifts actually change the roster.
            $map = []; $days = 0;
            foreach ($colDate as $c => $date) {
                $cell = trim((string) ($r[$c] ?? ''));
                if ($cell === '') continue;                       // blank -> not updated
                $key = strtoupper($cell);
                if (!isset($shiftLookup[$key])) {
                    $ref = XlsxWriter::colLetter($c + 1) . $excelRow;
                    $warnings[] = "Cell {$ref} (" . date('d M', strtotime($date))
                        . ") for {$code}: \"{$cell}\" isn't a known shift — left unchanged.";
                    continue;
                }
                $map[$date] = $shiftLookup[$key];
                $days++;
            }

            $employees[] = [
                'id'     => (int) $empByCode[$code]['id'],
                'emp_id' => $code,
                'name'   => trim((string) ($r[1] ?? '')),
                'map'    => $map,
                'days'   => $days,
            ];
        }

        if (!$employees && !$errors) {
            $errors[] = 'No employee rows were found in the file.';
        }

        return [
            'ok'        => empty($errors),
            'deptId'    => $deptId,
            'deptName'  => $deptName,
            'period'    => $period,
            'errors'    => $errors,
            'warnings'  => $warnings,
            'employees' => $employees,
            'summary'   => null,
        ];
    }

    private function parseFail(string $message, string $period, array $warnings = []): array
    {
        return [
            'ok' => false, 'deptId' => 0, 'deptName' => '', 'period' => $period,
            'errors' => [$message], 'warnings' => $warnings, 'employees' => [], 'summary' => null,
        ];
    }

    /** Write a validated import: one AllotShift per employee + one Schedule_Request. */
    private function applyRosterImport(array &$result): void
    {
        $deptId = (int) $result['deptId'];
        $period = (string) $result['period'];
        $empCount = 0; $assignments = 0;

        $this->db->begin();
        try {
            foreach ($result['employees'] as $e) {
                if (!$e['map']) continue;
                $this->saveLegacy((int) $e['id'], $period, $e['map']);
                $empCount++;
                $assignments += (int) $e['days'];
            }
            $submitted = $empCount > 0 ? $this->ensureLegacySubmissionByDept($deptId, $period) : false;
            $this->db->commit();
        } catch (\Throwable $ex) {
            $this->db->rollback();
            $result['ok'] = false;
            $result['errors'][] = 'Import failed while saving: ' . $ex->getMessage();
            return;
        }

        $result['summary'] = [
            'employees'   => $empCount,
            'assignments' => $assignments,
            'submitted'   => $submitted,
        ];
    }

}