<?php
namespace App\Payroll\Services;

use App\Core\Config;

/**
 * The link to the Duty Roster system.
 *
 * Payroll is a standalone app. The one thing it cannot produce on its own is
 * attendance — rostered shifts, actual punches, approved overtime, leave and
 * corrections — which all live in the Duty Roster / HRMS database. That
 * dependency is isolated behind this switch.
 *
 * While the link is OFF (the default) payroll runs on **assumed full
 * attendance**: every payable employee is paid their full structure, prorated
 * only for a mid-month join or leave. No absence, late, early-out or overtime
 * is applied, and screens say so. This lets salary structures, GOSI, loans,
 * holds, encashment, increments, settlements, the register, payslips and the
 * bank file all work now.
 *
 * When the link is turned ON (config: roster_link.enabled = true) Payroll
 * Attendance reads the roster tables and the calculation becomes
 * attendance-driven. Turning it on is a config change, not a code change.
 */
class RosterLink
{
    public static function enabled(): bool
    {
        return (bool) Config::get('roster_link.enabled', false);
    }

    /** Human-readable status for the UI banner. */
    public static function status(): array
    {
        if (self::enabled()) {
            return [
                'enabled' => true,
                'label'   => 'Duty Roster link: connected',
                'note'    => 'Payroll is calculated from rostered shifts, punches, approved overtime and leave.',
            ];
        }
        return [
            'enabled' => false,
            'label'   => 'Duty Roster link: not connected',
            'note'    => 'Attendance integration is pending. Payroll assumes full attendance — '
                       . 'absence, lates, early-outs and overtime are not applied yet.',
        ];
    }

    /**
     * A table reference for the roster source. Same database by default; when
     * the roster lives in another database on the same server, set
     * roster_link.db_prefix (e.g. "ASSH.dbo.") and it is prepended.
     */
    public static function ref(string $table): string
    {
        $prefix = (string) Config::get('roster_link.db_prefix', '');
        return $prefix !== '' ? $prefix . $table : $table;
    }
}
