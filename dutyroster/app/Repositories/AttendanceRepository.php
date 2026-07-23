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

    /** Actual attendance for one employee (by EmpCode) over a date range, keyed by 'Y-m-d'. */
    public function forEmployee(string $empCode, string $start, string $end): array
    {
        $prefix = Config::get('legacy.att_month_prefix', 'Atten_');
        $map = [];
        $mStart = strtotime(date('Y-m-01', strtotime($start)));
        $mEnd   = strtotime(date('Y-m-01', strtotime($end)));

        for ($ts = $mStart; $ts <= $mEnd; $ts = strtotime('+1 month', $ts)) {
            $tbl = $prefix . date('mY', $ts);   // e.g. Atten_092023
            try {
                $rows = $this->db->all(
                    "SELECT Todate AS work_date, Intime, Outtime, Intime1, Outtime1
                       FROM {$tbl}
                      WHERE Empid = :c AND Todate BETWEEN :a AND :b
                      ORDER BY Todate",
                    [':c' => $empCode, ':a' => $start, ':b' => $end . ' 23:59:59']
                );
            } catch (\Throwable $e) {
                continue; // month table may not exist yet
            }
            foreach ($rows as $r) {
                $date = substr((string) $r['work_date'], 0, 10);
                $punches = array_filter([
                    $r['Intime'], $r['Outtime'], $r['Intime1'], $r['Outtime1'],
                ], fn($v) => $v !== null && $v !== '');
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
