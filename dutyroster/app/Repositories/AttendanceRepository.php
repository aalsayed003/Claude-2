<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Reads daily attendance from the legacy monthly attendance tables
 * (Atten_MMYYYY, e.g. Atten_092023), keyed by EmpCode (Empid). Each has the
 * full four punches: Intime/Outtime + Intime1/Outtime1. A date range may span
 * several month tables, which are queried in turn (missing months skipped).
 *
 * Note: `attendancehistory` is the correction AUDIT log (Status = M/I/D +
 * Prev* values), not the daily source, so it is used only for corrections.
 */
class AttendanceRepository
{
    public function __construct(private Database $db) {}

    /**
     * Actual attendance for one employee over a date range, keyed by 'Y-m-d',
     * read from the live daily punch table (empPunchingDetails: empid, todate,
     * intime, outtime). `$keys` are the employee's candidate punch codes
     * (EmployeeId and/or EmpCode) — matched with IN so it works regardless of
     * which column the punches are keyed on.
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
        $tbl = lt('punch_daily');   // empPunchingDetails
        $ph = [];
        $params = [':a' => $start, ':b' => $end . ' 23:59:59'];
        foreach ($keys as $i => $k) {
            $ph[] = ":k{$i}";
            $params[":k{$i}"] = $k;
        }
        $in = implode(',', $ph);

        $rows = $this->db->all(
            "SELECT todate AS work_date, intime, outtime
               FROM {$tbl}
              WHERE empid IN ({$in}) AND todate BETWEEN :a AND :b
              ORDER BY todate",
            $params
        );

        $map = [];
        foreach ($rows as $r) {
            $date = substr((string) $r['work_date'], 0, 10);
            $in1  = $r['intime']  ?: null;
            $out1 = $r['outtime'] ?: null;
            $count = ($in1 ? 1 : 0) + ($out1 ? 1 : 0);
            $map[$date] = [
                'act_first_in'   => $in1,
                'act_first_out'  => $out1,
                'act_second_in'  => null,
                'act_second_out' => null,
                'punch_count'    => $count,
                'is_odd_punch'   => ($count % 2 === 1) ? 1 : 0,
                'status_code'    => '',
            ];
        }
        return $map;
    }

    /**
     * Derive a day's status from punch count and the scheduled shift.
     * (Atten_MMYYYY has no status letter; status comes from roster + punches.)
     */
    public static function deriveStatus(int $punchCount, ?array $sched): string
    {
        if ($sched && strtoupper((string) ($sched['code'] ?? '')) === 'DAY OFF') {
            return 'day_off';
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
