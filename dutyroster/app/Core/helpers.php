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
