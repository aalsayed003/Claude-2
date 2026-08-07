<?php
use App\Core\Config;
use App\Core\Auth;

/** HTML-escape. */
function e($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Build an app URL respecting base_url. */
function url(string $path = ''): string
{
    $base = rtrim(Config::get('app.base_url', '/'), '/');
    $path = ltrim($path, '/');
    return ($base === '' ? '' : $base) . '/' . $path;
}

/** CSRF hidden input. */
function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="_csrf" value="' . e($_SESSION['csrf']) . '">';
}

function old(string $key, $default = ''): string
{
    return e($_SESSION['old'][$key] ?? $default);
}

/**
 * Attendance period helpers. A "period" is the cutoff cycle, keyed by the
 * YYYY-MM of its *end month* (the month the person naturally thinks of it
 * as). With cutoff_day=16, picking "Aug-2026" shows the window
 * 2026-07-16 .. 2026-08-15.
 */
function period_bounds(string $periodKey): array
{
    $cut = (int) Config::get('attendance.cutoff_day', 16);
    [$y, $m] = array_map('intval', explode('-', $periodKey));
    $base = sprintf('%04d-%02d-%02d', $y, $m, $cut);
    $start = date('Y-m-d', strtotime($base . ' -1 month'));
    $end   = date('Y-m-d', strtotime($base . ' -1 day'));
    return [$start, $end];
}

/** Which period (keyed by its END month) does a given date fall into? */
function period_of(string $date): string
{
    $cut = (int) Config::get('attendance.cutoff_day', 16);
    $ts  = strtotime($date);
    $d   = (int) date('j', $ts);
    if ($d >= $cut) {
        return date('Y-m', strtotime(date('Y-m-01', $ts) . ' +1 month'));
    }
    return date('Y-m', $ts);
}

/** "Sep 2023" style label for a period key. */
function period_label(string $periodKey): string
{
    [$y, $m] = explode('-', $periodKey);
    return date('M Y', mktime(0, 0, 0, (int) $m, 1, (int) $y));
}

/**
 * First and last day of the CALENDAR month for a "YYYY-MM" key.
 * The duty roster is prepared per calendar month (config:
 * legacy.roster_calendar_month), unlike attendance/correction which run on
 * the payroll cutoff cycle (period_bounds() above, cutoff_day-based).
 */
function month_bounds(string $periodKey): array
{
    [$y, $m] = array_map('intval', explode('-', $periodKey));
    $start = sprintf('%04d-%02d-01', $y, $m);
    $end   = date('Y-m-t', strtotime($start));
    return [$start, $end];
}

function can(string $role): bool
{
    return Auth::atLeast($role);
}

/** True when the app is mapped onto the legacy ASSH tables. */
function legacy_mode(): bool
{
    return (bool) Config::get('legacy.enabled', false);
}

/** Resolve a legacy table name by mapping key, e.g. lt('employee') -> 'Employee'.
 *  Config may override any key; otherwise the correct legacy default is used. */
function lt(string $key): string
{
    static $def = [
        'employee' => 'Employee', 'department' => 'Department', 'designation' => 'Designation',
        'shift' => 'Shift', 'roster_hdr' => 'AllotShift', 'roster_dtl' => 'AllotShiftDetail',
        'att_history' => 'attendancehistory', 'punch_daily' => 'empPunchingDetails',
        'dashboard' => 'DRMainDashBoard', 'sched_req' => 'Schedule_Request',
        'sched_act' => 'Schedule_RequestActions', 'sched_status' => 'ScheduleStatus',
        'ot' => 'DR_OverTime', 'ot_reason' => 'DR_OvertimeReason', 'change_sched' => 'DR_ChangeSchedule',
        'leave' => 'leave', 'leave_app' => 'LeaveApplication', 'leave_bal' => 'leavebalance',
        'sys_users' => 'RA_SystemUsers',
        'correction_table' => 'DR_CorrectionRequest', 'working_hours_table' => 'EmployeeWorkingHours',
    ];
    return Config::get('legacy.' . $key, $def[$key] ?? $key);
}

/** Derive the 9-digit biometric PIN from an employee code (e.g. 01732 -> 000001732). */
function pin_from_code(string $empCode): string
{
    $digits = preg_replace('/\D/', '', $empCode);
    return $digits === '' ? '' : str_pad($digits, 9, '0', STR_PAD_LEFT);
}

/** "D1 (08:00–16:00 · 8h)" style label for a shift row (id/code/first_in/... shape). */
function shift_label(array $s): string
{
    $code = trim((string) ($s['code'] ?? ''));
    if ($code === '') return '';
    $t = fn($v) => ($v !== null && trim((string) $v) !== '')
        ? date('H:i', strtotime((string) $v)) : null;
    $in  = $t($s['first_in'] ?? null);
    $out = $t($s['second_out'] ?? null) ?: $t($s['first_out'] ?? null);
    if (!$in || !$out) return $code;   // DAY OFF / PUBLIC HOLIDAY / no times
    $hs = rtrim(rtrim(number_format((float) ($s['total_hours'] ?? 0), 1), '0'), '.');
    return "{$code} ({$in}–{$out} · {$hs}h)";
}

/* ============================================================================
 * Payroll module helpers (from ASSH-Payroll). The period_bounds/period_of
 * functions above are the ROSTER (end-month) definitions; payroll's month
 * mapping honours payroll.month_is_period_end (set false in the merged config
 * so the payroll month equals the period's end month).
 * ========================================================================== */

/** Resolve a payroll table name by mapping key, e.g. pt('register') -> 'CurrentMonth'. */
function pt(string $key): string
{
    static $def = [
        'structure' => 'CurrentDetails', 'register' => 'CurrentMonth',
        'monthly_allow' => 'MonthlyAllowances', 'ot_month' => 'overtime',
        'run' => 'Pay_Run', 'run_audit' => 'Pay_RunAudit',
        'statutory' => 'Pay_EmployeeStatutory', 'bank' => 'Pay_Bank',
        'gosi_rate' => 'Pay_GosiRate', 'loan' => 'Pay_Loan',
        'loan_inst' => 'Pay_LoanInstallment', 'settlement' => 'Pay_Settlement',
        'wps_export' => 'Pay_WpsExport', 'salary_hold' => 'Pay_SalaryHold',
        'leave_encash' => 'Pay_LeaveEncashment', 'indemnity_prov' => 'Pay_IndemnityProvision',
        'leave_prov' => 'Pay_LeaveProvision', 'leave_request' => 'Pay_LeaveRequest',
        'hr_request' => 'Pay_HrRequest', 'cme_req' => 'Pay_CmeRequirement',
        'cme_activity' => 'Pay_CmeActivity', 'cme_cat_req' => 'Pay_CmeCategoryRequirement',
    ];
    return \App\Core\Config::get('payroll.tables.' . $key, $def[$key] ?? $key);
}

/** The payroll month a cutoff period belongs to, as 'YYYY-MM-01'. */
function payroll_month_of_period(string $periodKey): string
{
    [$y, $m] = array_map('intval', explode('-', $periodKey));
    $ts = mktime(0, 0, 0, $m, 1, $y);
    if (\App\Core\Config::get('payroll.month_is_period_end', true)) {
        $ts = strtotime('+1 month', $ts);
    }
    return date('Y-m-01', $ts);
}

/** Inverse of payroll_month_of_period(): the cutoff period key for a payroll month. */
function period_of_payroll_month(string $payrollMonth): string
{
    $ts = strtotime(substr($payrollMonth, 0, 7) . '-01');
    if (\App\Core\Config::get('payroll.month_is_period_end', true)) {
        $ts = strtotime('-1 month', $ts);
    }
    return date('Y-m', $ts);
}

/** Format an amount in the payroll currency's precision. */
function money($v): string
{
    return number_format((float) $v, (int) \App\Core\Config::get('payroll.decimals', 3), '.', ',');
}

/** Round to the payroll currency's precision. */
function money_round($v): float
{
    return round((float) $v, (int) \App\Core\Config::get('payroll.decimals', 3));
}

/**
 * Count of duty-roster items a department head (or above) can still act on:
 * roster submissions + schedule-change + attendance-correction requests.
 * Used for the mobile bottom-nav "Approvals" badge. Never throws.
 */
function hod_pending_count(): int
{
    try {
        if (!\App\Core\Auth::atLeast('dept_head')) return 0;
        $db   = \App\Core\Database::app();
        $from = '2000-01-01';
        $to   = date('Y-m-d', strtotime('+1 day'));
        $n  = (new \App\Roster\Repositories\ScheduleRequestRepository($db))->pendingCount();
        $n += (new \App\Roster\Repositories\ScheduleChangeRepository($db))->pendingCount($from, $to);
        $n += (new \App\Roster\Repositories\CorrectionRepository($db))->pendingCount($from, $to);
        return (int) $n;
    } catch (\Throwable $e) {
        return 0;
    }
}
