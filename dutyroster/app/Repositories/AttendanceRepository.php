<?php
namespace App\Repositories;

use App\Core\Database;
use App\Core\Config;

/**
 * Reads daily attendance from the legacy `attendancehistory` table, keyed by
 * EmpCode (varchar) and ToDate. Status is a char(1) whose meaning is mapped
 * via config 'legacy.status_map' (kept configurable until the real codes are
 * confirmed from the data).
 */
class AttendanceRepository
{
    public function __construct(private Database $db) {}

    /** Actual attendance for one employee (by EmpCode) over a date range, keyed by 'Y-m-d'. */
    public function forEmployee(string $empCode, string $start, string $end): array
    {
        $att = lt('att_history');
        $rows = $this->db->all(
            "SELECT ToDate AS work_date, FirstIn, FirstOut, SecondIn, SecondOut, Status
               FROM {$att}
              WHERE EmpId = :c AND ToDate BETWEEN :a AND :b
              ORDER BY ToDate",
            [':c' => $empCode, ':a' => $start, ':b' => $end . ' 23:59:59']
        );

        $map = [];
        foreach ($rows as $r) {
            $date = substr((string) $r['work_date'], 0, 10);
            $punches = array_filter([
                $r['FirstIn'], $r['FirstOut'], $r['SecondIn'], $r['SecondOut'],
            ], fn($v) => $v !== null && $v !== '');
            $map[$date] = [
                'act_first_in'   => $r['FirstIn']   ?: null,
                'act_first_out'  => $r['FirstOut']  ?: null,
                'act_second_in'  => $r['SecondIn']  ?: null,
                'act_second_out' => $r['SecondOut'] ?: null,
                'punch_count'    => count($punches),
                'is_odd_punch'   => (count($punches) % 2 === 1) ? 1 : 0,
                'status_code'    => trim((string) ($r['Status'] ?? '')),
            ];
        }
        return $map;
    }

    /** Map the legacy char(1) Status to the app's status keyword. */
    public static function mapStatus(string $code, int $punchCount): string
    {
        $code = strtoupper(trim($code));
        $map = Config::get('legacy.status_map', [
            'P' => 'present', 'A' => 'absent', 'H' => 'holiday',
            'O' => 'day_off', 'W' => 'day_off', 'L' => 'leave',
        ]);
        if ($code !== '' && isset($map[$code])) {
            return $map[$code];
        }
        return $punchCount > 0 ? 'present' : 'no_punch';
    }
}
