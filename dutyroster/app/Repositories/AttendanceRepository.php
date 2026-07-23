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
        // Prefer the properly-paired monthly Atten_ tables; fall back to the
        // daily punch table for any day Atten_ doesn't cover.
        $map = $this->fromDaily($keys, $start, $end);
        foreach ($this->fromAtten($keys, $start, $end) as $date => $row) {
            $map[$date] = $row;
        }
        ksort($map);
        return $map;
    }

    /** Build the IN(...) placeholder list + params for the candidate keys. */
    private function keyIn(array $keys, array $extra): array
    {
        $ph = [];
        $params = $extra;
        foreach ($keys as $i => $k) {
            $ph[] = ":k{$i}";
            $params[":k{$i}"] = $k;
        }
        return [implode(',', $ph), $params];
    }

    /** Properly-paired attendance from the monthly Atten_MMYYYY tables. */
    private function fromAtten(array $keys, string $start, string $end): array
    {
        $prefix = Config::get('legacy.att_month_prefix', 'Atten_');
        $map = [];
        $mStart = strtotime(date('Y-m-01', strtotime($start)));
        $mEnd   = strtotime(date('Y-m-01', strtotime($end)));

        for ($ts = $mStart; $ts <= $mEnd; $ts = strtotime('+1 month', $ts)) {
            $tbl = $prefix . date('mY', $ts);
            [$in, $params] = $this->keyIn($keys, [':a' => $start, ':b' => $end . ' 23:59:59']);
            try {
                $rows = $this->db->all(
                    "SELECT Todate AS work_date, Intime, Outtime, Intime1, Outtime1
                       FROM {$tbl}
                      WHERE Empid IN ({$in}) AND Todate BETWEEN :a AND :b
                      ORDER BY Todate",
                    $params
                );
            } catch (\Throwable $e) {
                continue; // month table absent
            }
            foreach ($rows as $r) {
                $date = substr((string) $r['work_date'], 0, 10);
                $punches = array_filter([$r['Intime'], $r['Outtime'], $r['Intime1'], $r['Outtime1']],
                    fn($v) => $v !== null && $v !== '');
                $map[$date] = [
                    'act_first_in'   => $r['Intime']   ?: null,
                    'act_first_out'  => $r['Outtime']  ?: null,
                    'act_second_in'  => $r['Intime1']  ?: null,
                    'act_second_out' => $r['Outtime1'] ?: null,
                    'punch_count'    => count($punches),
                    'is_odd_punch'   => (count($punches) % 2 === 1) ? 1 : 0,
                    'status_code'    => '',
                ];
            }
        }
        return $map;
    }

    /** Fallback: aggregate the daily punch table (empPunchingDetails). */
    private function fromDaily(array $keys, string $start, string $end): array
    {
        $tbl = lt('punch_daily');
        [$in, $params] = $this->keyIn($keys, [':a' => $start, ':b' => $end . ' 23:59:59']);
        try {
            $rows = $this->db->all(
                "SELECT todate AS work_date, intime, outtime
                   FROM {$tbl}
                  WHERE empid IN ({$in}) AND todate BETWEEN :a AND :b
                  ORDER BY todate",
                $params
            );
        } catch (\Throwable $e) {
            return [];
        }

        $agg = [];
        foreach ($rows as $r) {
            $date = substr((string) $r['work_date'], 0, 10);
            $in1  = $r['intime']  ?: null;
            $out1 = $r['outtime'] ?: null;
            if (!isset($agg[$date])) {
                $agg[$date] = ['in' => null, 'out' => null];
            }
            if ($in1 !== null && ($agg[$date]['in'] === null || $in1 < $agg[$date]['in'])) {
                $agg[$date]['in'] = $in1;
            }
            if ($out1 !== null && ($agg[$date]['out'] === null || $out1 > $agg[$date]['out'])) {
                $agg[$date]['out'] = $out1;
            }
        }

        $map = [];
        foreach ($agg as $date => $v) {
            $count = ($v['in'] ? 1 : 0) + ($v['out'] ? 1 : 0);
            $map[$date] = [
                'act_first_in'   => $v['in'],
                'act_first_out'  => $v['out'],
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
