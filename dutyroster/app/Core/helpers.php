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

/** Derive the 9-digit biometric PIN from an employee code (e.g. 01732 -> 000001732). */
function pin_from_code(string $empCode): string
{
    $digits = preg_replace('/\D/', '', $empCode);
    return $digits === '' ? '' : str_pad($digits, 9, '0', STR_PAD_LEFT);
}
