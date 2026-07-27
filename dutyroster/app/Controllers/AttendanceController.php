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
            if (legacy_mode()) {
                $emp  = (new \App\Repositories\EmployeeRepository($this->db))->find($empId);
                $rows = $emp ? $this->legacyAttendance($emp, $empId, $from, $to) : [];
            } else {
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
        }

        $legacyEmployees = legacy_mode() && $canPickAnyone
            ? (new \App\Repositories\EmployeeRepository($this->db))->search('')
            : ($canPickAnyone ? $this->db->all("SELECT id, emp_id, full_name FROM employees WHERE active = 1 ORDER BY full_name") : []);

        $this->view('attendance/index', [
            'title'         => 'View Attendance',
            'employees'     => $legacyEmployees,
            'canPickAnyone' => $canPickAnyone,
            'emp'           => $emp,
            'rows'          => $rows,
            'from'          => $from,
            'to'            => $to,
        ]);
    }

    /**
     * Build attendance rows for the view by merging legacy actual attendance
     * (attendancehistory) with the scheduled roster (AllotShiftDetail/Shift),
     * one row per day in the range, and deriving late-in / early-out.
     */
    private function legacyAttendance(array $emp, int $empId, string $from, string $to): array
    {
        $keys      = [$emp['emp_id'] ?? null, $emp['emp_code'] ?? null];
        $actual    = (new \App\Repositories\AttendanceRepository($this->db))->forEmployee($keys, $from, $to);
        $scheduled = (new \App\Repositories\RosterRepository($this->db))->forEmployeeRange($empId, $from, $to);

        $rows = [];
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $a = $actual[$date] ?? null;
            $s = $scheduled[$date] ?? null;
            if ($a === null && $s === null) {
                continue;
            }

            $punchCount = $a['punch_count'] ?? 0;
            $status = \App\Repositories\AttendanceRepository::deriveStatus($punchCount, $s);

            // Grace: late-in counts only when > grace_late_min (default 15 -> 16+).
            $graceLate  = (int) \App\Core\Config::get('attendance.grace_late_min', 15);
            $graceEarly = (int) \App\Core\Config::get('attendance.grace_early_min', 15);
            $late = $early = 0;
            if ($s && $a && $status === 'present') {
                if ($s['first_in'] && $a['act_first_in']) {
                    $d = (strtotime($a['act_first_in']) - strtotime($date . ' ' . $s['first_in'])) / 60;
                    if ($d > $graceLate) $late = (int) round($d);
                }
                $schedOut = $s['second_out'] ?: $s['first_out'];
                $actOut   = $a['act_second_out'] ?: $a['act_first_out'];
                if ($schedOut && $actOut) {
                    $d = (strtotime($date . ' ' . $schedOut) - strtotime($actOut)) / 60;
                    if ($d > $graceEarly) $early = (int) round($d);
                }
            }

            $rows[] = [
                'work_date'      => $date,
                'act_first_in'   => $a['act_first_in']   ?? null,
                'act_first_out'  => $a['act_first_out']  ?? null,
                'act_second_in'  => $a['act_second_in']  ?? null,
                'act_second_out' => $a['act_second_out'] ?? null,
                'sch_first_in'   => $s['first_in']   ?? null,
                'sch_first_out'  => $s['first_out']  ?? null,
                'sch_second_in'  => $s['second_in']  ?? null,
                'sch_second_out' => $s['second_out'] ?? null,
                'shift_code'     => $s['code'] ?? null,
                'late_in_min'    => $late,
                'early_out_min'  => $early,
                'is_odd_punch'   => $a['is_odd_punch'] ?? 0,
                'status'         => $status,
            ];
        }
        return $rows;
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
