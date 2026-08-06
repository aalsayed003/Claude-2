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
 * YYYY-MM of its *start month*. With cutoff_day=16, Sep-2023 period runs
 * 2023-09-16 .. 2023-10-15.
 */
function period_bounds(string $periodKey): array
{
    $cut = (int) Config::get('attendance.cutoff_day', 16);
    [$y, $m] = array_map('intval', explode('-', $periodKey));
    $start = sprintf('%04d-%02d-%02d', $y, $m, $cut);
    $endTs = strtotime($start . ' +1 month -1 day');
    return [$start, date('Y-m-d', $endTs)];
}

/** Which period does a given date fall into? */
function period_of(string $date): string
{
    $cut = (int) Config::get('attendance.cutoff_day', 16);
    $ts  = strtotime($date);
    $d   = (int) date('j', $ts);
    if ($d >= $cut) {
        return date('Y-m', $ts);
    }
    return date('Y-m', strtotime(date('Y-m-01', $ts) . ' -1 month'));
}

/** "Sep 2023" style label for a period key. */
function period_label(string $periodKey): string
{
    [$y, $m] = explode('-', $periodKey);
    return date('M Y', mktime(0, 0, 0, (int) $m, 1, (int) $y));
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
    ];
    return Config::get('legacy.' . $key, $def[$key] ?? $key);
}

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
    return Config::get('payroll.tables.' . $key, $def[$key] ?? $key);
}

/**
 * The payroll month a cutoff period belongs to, as 'YYYY-MM-01'.
 * Period keys are the START month of the cycle (see period_bounds), so with
 * month_is_period_end the 2026-06 period (16 Jun .. 15 Jul) is July payroll.
 */
function payroll_month_of_period(string $periodKey): string
{
    [$y, $m] = array_map('intval', explode('-', $periodKey));
    $ts = mktime(0, 0, 0, $m, 1, $y);
    if (Config::get('payroll.month_is_period_end', true)) {
        $ts = strtotime('+1 month', $ts);
    }
    return date('Y-m-01', $ts);
}

/** Inverse of payroll_month_of_period(): the cutoff period key for a payroll month. */
function period_of_payroll_month(string $payrollMonth): string
{
    $ts = strtotime(substr($payrollMonth, 0, 7) . '-01');
    if (Config::get('payroll.month_is_period_end', true)) {
        $ts = strtotime('-1 month', $ts);
    }
    return date('Y-m', $ts);
}

/** Format an amount in the payroll currency's precision. */
function money($v): string
{
    return number_format((float) $v, (int) Config::get('payroll.decimals', 3), '.', ',');
}

/** Round to the payroll currency's precision. */
function money_round($v): float
{
    return round((float) $v, (int) Config::get('payroll.decimals', 3));
}

/** Derive the 9-digit biometric PIN from an employee code (e.g. 01732 -> 000001732). */
function pin_from_code(string $empCode): string
{
    $digits = preg_replace('/\D/', '', $empCode);
    return $digits === '' ? '' : str_pad($digits, 9, '0', STR_PAD_LEFT);
}
