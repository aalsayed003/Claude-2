<?php
namespace App\Roster\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;

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

        $reasons = [];
        $punchSourceOk = true;
        if (legacy_mode()) {
            $punchSourceOk = (new \App\Roster\Repositories\AttendanceRepository($this->db))->punchSourceAvailable();
            $empRepo = new \App\Roster\Repositories\EmployeeRepository($this->db);
            $emp = $empId ? $empRepo->find($empId) : null;
            // Eligible OT is derived from the roster vs the raw punches: working-day
            // early-in / late-out, plus off-day / public-holiday worked days.
            $eligible = $emp
                ? \App\Roster\Services\OvertimeEligibility::forEmployee($this->db, $emp, $empId, $cutFrom, $cutTo)
                : [];
            $requests = $empId
                ? (new \App\Roster\Repositories\OvertimeRepository($this->db))->forEmployee($empId, $cutFrom, $cutTo)
                : [];
            $employees = Auth::atLeast('dept_head') ? $empRepo->search('') : [];
            $reasons = (new \App\Roster\Repositories\ReasonRepository($this->db))->overtime();
        } else {
            $emp = $empId ? $this->db->one("SELECT * FROM employees WHERE id=:id", [':id'=>$empId]) : null;
            $eligible = $empId ? $this->db->all(
                "SELECT work_date, ot_early_min, ot_late_min FROM attendance
                  WHERE employee_id = :e AND work_date BETWEEN :a AND :b
                    AND (ot_early_min > 0 OR ot_late_min > 0)
                  ORDER BY work_date",
                [':e'=>$empId, ':a'=>$cutFrom, ':b'=>$cutTo]
            ) : [];
            $requests = $empId ? $this->db->all(
                $this->db->limit(
                    "SELECT * FROM overtime_requests WHERE employee_id = :e
                      ORDER BY requested_at DESC", 50),
                [':e'=>$empId]
            ) : [];
            $employees = Auth::atLeast('dept_head')
                ? $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active=1 ORDER BY full_name") : [];
        }

        $this->view('overtime/index', [
            'title'     => 'Overtime',
            'employees' => $employees,
            'emp'       => $emp,
            'period'    => $period,
            'cutFrom'   => $cutFrom,
            'cutTo'     => $cutTo,
            'punchSourceOk' => $punchSourceOk,
            'eligible'  => $eligible,
            'requests'  => $requests,
            'reasons'   => $reasons,
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
        $mins = 0; $endTs = null;
        if ($from && $to) {
            $a = strtotime($date . ' ' . $from);
            $b = strtotime($date . ' ' . $to);
            if ($b < $a) { $b += 86400; }
            $mins = (int) round(($b - $a) / 60);
            $endTs = $b;
        }

        if (legacy_mode()) {
            $this->saveLegacyOvertime($empId, $date, $from, $to, $endTs, $mins);
            $this->flash('success', 'Overtime request submitted.');
            $this->redirect('overtime?employee_id=' . $empId . '&period=' . period_of($date));
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

    /**
     * Write an overtime request to the legacy `DR_OverTime` table. Most columns
     * there are NOT NULL (Designation, the datetime pair, TotalOverTime, ReasonID,
     * Remarks, StateID, ClaimTime, RejectReason), so all are supplied. Hours are
     * stored as a numeric total; RequestID is supplied identity-safe.
     */
    private function saveLegacyOvertime(int $empId, string $date, ?string $from, ?string $to, ?int $endTs, int $mins): void
    {
        $t   = lt('ot');
        $emp = (new \App\Roster\Repositories\EmployeeRepository($this->db))->find($empId);
        $start = $from ? ($date . ' ' . $from . ':00') : ($date . ' 00:00:00');
        $end   = $endTs !== null ? date('Y-m-d H:i:s', $endTs) : ($date . ' 00:00:00');
        $reason = $this->input('reason');
        $row = [
            'EmployeeID'    => $empId,
            'CategoryID'    => null,
            'Designation'   => (string) ($emp['designation'] ?? ''),
            'RequestDate'   => date('Y-m-d H:i:s'),
            'OverTimeDate'  => $date,
            'StartOverTime' => $start,
            'EndOverTime'   => $end,
            'TotalOverTime' => round($mins / 60, 2),
            'ReasonID'      => is_numeric($reason) ? (int) $reason : 0,
            'Remarks'       => (string) ($this->input('remark') ?? ''),
            'StateID'       => (int) Config::get('legacy.dr_initial_state', 1),
            'ClaimTime'     => 0,
            'RejectReason'  => '',
            'IsExpired'     => 0,
        ];
        $this->db->insertLegacy($t, $row, 'RequestID');
    }
}
