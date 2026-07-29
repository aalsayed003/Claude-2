<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Reads daily attendance from the legacy monthly attendance tables
 * (Atten_MMYYYY, e.g. Atten_072026), keyed by Empid. Each row already holds
 * the day's four resolved punch slots: Intime/Outtime (first pair) and
 * Intime1/Outtime1 (second pair, split shifts) — one row per employee per
 * day. A date range may span several month tables, which are queried in
 * turn (missing/absent months are skipped).
 *
 * IMPORTANT: earlier versions of this class read from `empPunchingDetails`
 * instead. That table stores ONE ROW PER PUNCH EVENT (not per day) — a
 * single day can have 4-6 rows, each with only intime OR only outtime set.
 * The old code kept the last row seen per date and discarded the rest, so
 * a day's attendance randomly showed only an in-punch or only an out-punch.
 * Atten_MMYYYY does not have that problem: confirmed via
 * INFORMATION_SCHEMA.COLUMNS that it stores Empid, DeptID, Todate, Intime,
 * Outtime, Intime1, Outtime1, Operatorid — one row per day already resolved.
 * See database/migration/README.md, which flags Atten_MMYYYY as required
 * for View Attendance to show punches correctly.
 *
 * Note: `attendancehistory` is the correction AUDIT log (Status = M/I/D +
 * Prev* values), not the daily source, so it is used only for corrections.
 */
class AttendanceRepository
{
    public function __construct(private Database $db) {}

    /**
     * Actual attendance for one employee over a date range, keyed by 'Y-m-d'.
     * `$keys` are the employee's candidate punch codes (EmployeeId and/or
     * EmpCode) — matched with IN so it works regardless of which column the
     * Atten_ tables are keyed on.
     */
    public function forEmployee($keys, string $start, string $end): array
    {
        $keys = array_values(array_unique(array_filter(
            is_array($keys) ? $keys : [$keys],
            fn($v) => $v !== null && $v !== ''
        )));
        if (!$keys) {
            return [];
        }

        $map = [];
        foreach ($this->monthTables($start, $end) as [$table, $from, $to]) {
            if (!$this->tableExists($table)) {
                continue;
            }

            $ph = [];
            $params = [':a' => $from, ':b' => $to . ' 23:59:59'];
            foreach ($keys as $i => $k) {
                $ph[] = ":k{$i}";
                $params[":k{$i}"] = $k;
            }
            $in = implode(',', $ph);

            $rows = $this->db->all(
                "SELECT Todate AS work_date, Intime, Outtime, Intime1, Outtime1
                   FROM {$table}
                  WHERE Empid IN ({$in}) AND Todate BETWEEN :a AND :b
                  ORDER BY Todate",
                $params
            );

            foreach ($rows as $r) {
                $date = substr((string) $r['work_date'], 0, 10);
                $in1  = $r['Intime']   ?: null;
                $out1 = $r['Outtime']  ?: null;
                $in2  = $r['Intime1']  ?: null;
                $out2 = $r['Outtime1'] ?: null;
                $count = ($in1 ? 1 : 0) + ($out1 ? 1 : 0) + ($in2 ? 1 : 0) + ($out2 ? 1 : 0);
                $map[$date] = [
                    'act_first_in'   => $in1,
                    'act_first_out'  => $out1,
                    'act_second_in'  => $in2,
                    'act_second_out' => $out2,
                    'punch_count'    => $count,
                    'is_odd_punch'   => ($count % 2 === 1) ? 1 : 0,
                    'status_code'    => '',
                ];
            }
        }
        return $map;
    }

    /**
     * Raw biometric punches for an employee between two datetimes, as sorted
     * unix timestamps. Reads the configured raw punch table (default
     * `checkinout`, one row per punch) matched on the 9-digit PIN (and any
     * fallback codes). Returns NULL when that table isn't present, so callers
     * can fall back to the pre-paired Atten_MMYYYY tables.
     *
     * @param string[] $pins  candidate punch codes (9-digit PIN, emp_id, …)
     */
    public function rawPunches(array $pins, string $fromDt, string $toDt): ?array
    {
        $table = Config::get('legacy.punch_table', 'checkinout');
        if (!$this->tableExists($table)) {
            return null;
        }
        $pins = array_values(array_unique(array_filter($pins, fn($v) => $v !== null && $v !== '')));
        if (!$pins) {
            return [];
        }
        $pinCol  = Config::get('legacy.punch_pin_col', 'pin');
        $timeCol = Config::get('legacy.punch_time_col', 'checktime');

        $ph = [];
        $params = [':a' => $fromDt, ':b' => $toDt];
        foreach ($pins as $i => $p) {
            $ph[] = ":p{$i}";
            $params[":p{$i}"] = $p;
        }
        $in = implode(',', $ph);

        $rows = $this->db->all(
            "SELECT {$timeCol} AS t FROM {$table}
              WHERE {$pinCol} IN ({$in}) AND {$timeCol} BETWEEN :a AND :b
              ORDER BY {$timeCol}",
            $params
        );

        $ts = [];
        foreach ($rows as $r) {
            $u = strtotime((string) $r['t']);
            if ($u !== false) $ts[] = $u;
        }
        $ts = array_values(array_unique($ts));
        sort($ts);
        return $ts;
    }

    /**
     * Distinct Atten_MMYYYY table names (+ date bounds clipped to that
     * calendar month) spanned by [$start, $end]. Table name = the
     * configured prefix (default 'Atten_') + date('mY'), e.g. 'Atten_072026'
     * for July 2026.
     */
    private function monthTables(string $start, string $end): array
    {
        $prefix = Config::get('legacy.att_month_prefix', 'Atten_');
        $out = [];
        $ts    = strtotime(date('Y-m-01', strtotime($start)));
        $endTs = strtotime($end);
        while ($ts <= $endTs) {
            $table      = $prefix . date('mY', $ts);
            $monthStart = date('Y-m-01', $ts);
            $monthEnd   = date('Y-m-t', $ts);
            $out[] = [$table, max($monthStart, $start), min($monthEnd, $end)];
            $ts = strtotime('+1 month', $ts);
        }
        return $out;
    }

    /** True if the given table exists in the current database (driver-aware). */
    private function tableExists(string $table): bool
    {
        switch ($this->db->driver()) {
            case 'sqlsrv':
                return (bool) $this->db->value(
                    "SELECT CASE WHEN OBJECT_ID(:t, 'U') IS NOT NULL THEN 1 ELSE 0 END",
                    [':t' => $table]
                );
            case 'sqlite':
                return (bool) $this->db->value(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :t",
                    [':t' => $table]
                );
            default: // mysql / pgsql
                return (bool) $this->db->value(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = :t",
                    [':t' => $table]
                );
        }
    }

    /**
     * Derive a day's status from punch count and the scheduled shift.
     * (Atten_MMYYYY has no status letter; status comes from roster + punches.)
     */
    public static function deriveStatus(int $punchCount, ?array $sched): string
    {
        $code = strtoupper((string) ($sched['code'] ?? ''));
        if ($sched && $code === 'DAY OFF') {
            return 'day_off';
        }
        if ($sched && str_contains($code, 'HOLIDAY')) {
            return 'holiday';
        }
        if ($punchCount > 0) {
            return 'present';
        }
        return $sched ? 'absent' : 'no_punch';
    }

    /** Back-compat shim used by screens still passing a status code. */
    public static function mapStatus(string $code, int $punchCount): string
    {
        return $punchCount > 0 ? 'present' : 'no_punch';
    }
}