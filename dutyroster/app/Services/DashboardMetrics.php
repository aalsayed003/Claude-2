<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Config;
use App\Repositories\AttendanceRepository;

/**
 * Dashboard "today" figures derived from the SAME roster + raw-punch pairing as
 * View Attendance, computed in BULK (one roster query + one day-bounded punch
 * query, then paired in memory). todayCounts() gives the tile numbers;
 * todayDetails() gives the per-person list behind a tile (the drill-down).
 */
class DashboardMetrics
{
    /** Metric key => predicate on a per-person row. */
    private const METRICS = ['late', 'early', 'absent', 'odd', 'day_off'];

    /**
     * @return array{absent:int,odd_punch:int,late:int,early:int,day_off:int}
     */
    public static function todayCounts(Database $db, string $date): array
    {
        $counts = ['absent' => 0, 'odd_punch' => 0, 'late' => 0, 'early' => 0, 'day_off' => 0];
        foreach (self::todayRows($db, $date) as $r) {
            if ($r['status'] === 'absent')          $counts['absent']++;
            if ($r['is_odd_punch'])                 $counts['odd_punch']++;
            if ($r['late_in_min']  > 0)             $counts['late']++;
            if ($r['early_out_min'] > 0)            $counts['early']++;
            if (in_array($r['status'], ['day_off', 'holiday'], true)) $counts['day_off']++;
        }
        return $counts;
    }

    /** The people behind a tile: rows matching $metric (late|early|absent|odd|day_off). */
    public static function todayDetails(Database $db, string $date, string $metric): array
    {
        if (!in_array($metric, self::METRICS, true)) {
            return [];
        }
        $match = match ($metric) {
            'late'    => fn($r) => $r['late_in_min']  > 0,
            'early'   => fn($r) => $r['early_out_min'] > 0,
            'absent'  => fn($r) => $r['status'] === 'absent',
            'odd'     => fn($r) => (bool) $r['is_odd_punch'],
            'day_off' => fn($r) => in_array($r['status'], ['day_off', 'holiday'], true),
        };
        return array_values(array_filter(self::todayRows($db, $date), $match));
    }

    /** Human label for a metric key. */
    public static function metricLabel(string $metric): string
    {
        return [
            'late' => 'Late In', 'early' => 'Early Out', 'absent' => 'Absent',
            'odd' => 'Odd Punch', 'day_off' => 'Day Off',
        ][$metric] ?? $metric;
    }

    /**
     * Per-person paired result for everyone rostered on $date. One roster query +
     * one day-bounded punch query; pairing done in memory.
     * @return array<int,array>
     */
    private static function todayRows(Database $db, string $date): array
    {
        $hdr = lt('roster_hdr');
        $dtl = lt('roster_dtl');
        $emp = lt('employee');
        $shift = lt('shift');

        $people = $db->all(
            "SELECT h.Empid AS id, e.EmployeeId AS emp_id, e.EmpCode AS emp_code, e.Name AS name,
                    s.Name AS code, d.Intime, d.Outtime, d.InTime1, d.OutTime1,
                    s.FromTime, s.ToTime, s.FromTime1, s.ToTime1
               FROM {$dtl} d
               JOIN {$hdr} h ON h.ID = d.AllotId AND h.Deleted = 0
               JOIN {$emp} e ON e.ID = h.Empid
               JOIN {$shift} s ON s.ID = d.Shiftid
              WHERE d.Deleted = 0 AND d.ShiftDate BETWEEN :a AND :b",
            [':a' => $date, ':b' => $date . ' 23:59:59']
        );
        if (!$people) {
            return [];
        }
        [$byPin, $havePunches] = self::punchesByPin($db, $date);

        $out = [];
        foreach ($people as $p) {
            $sched = self::sched($p);
            $code  = strtoupper((string) ($sched['code'] ?? ''));
            $isOff = $code === 'DAY OFF' || str_contains($code, 'HOLIDAY');

            if ($isOff) {
                $out[] = self::row($p, $sched, 'day_off', ['punch_count' => 0], $havePunches);
                continue;
            }
            $a = $havePunches
                ? PunchPairer::pair($date, $sched, self::tsFor($p, $byPin))
                : ['punch_count' => 0, 'is_odd_punch' => 0, 'late_in_min' => 0, 'early_out_min' => 0];
            $status = $havePunches
                ? AttendanceRepository::deriveStatus($a['punch_count'] ?? 0, $sched)
                : 'unknown';
            $out[] = self::row($p, $sched, $status, $a, $havePunches);
        }
        return $out;
    }

    private static function row(array $p, array $sched, string $status, array $a, bool $havePunches): array
    {
        $hm = fn($v) => $v ? date('H:i', strtotime((string) $v)) : null;
        return [
            'id'            => (int) $p['id'],
            'emp_id'        => (string) ($p['emp_id'] ?? ''),
            'name'          => trim((string) ($p['name'] ?? '')),
            'shift_code'    => $sched['code'] ?? '',
            'status'        => $status,
            'sched_in'      => $sched['first_in'] ?? null,
            'sched_out'     => $sched['second_out'] ?: ($sched['first_out'] ?? null),
            'act_in'        => $hm($a['act_first_in'] ?? null),
            'act_out'       => $hm(($a['act_second_out'] ?? null) ?: ($a['act_first_out'] ?? null)),
            'late_in_min'   => (int) ($a['late_in_min'] ?? 0),
            'early_out_min' => (int) ($a['early_out_min'] ?? 0),
            'is_odd_punch'  => (int) ($a['is_odd_punch'] ?? 0),
        ];
    }

    /** pin => sorted unix timestamps for the day window; [map, sourceReachable]. */
    private static function punchesByPin(Database $db, string $date): array
    {
        $table   = Config::get('legacy.punch_table', 'checkinout');
        $pinCol  = Config::get('legacy.punch_pin_col', 'pin');
        $timeCol = Config::get('legacy.punch_time_col', 'checktime');
        $from = $date . ' 00:00:00';
        $to   = date('Y-m-d', strtotime($date . ' +1 day')) . ' 12:00:00';
        try {
            $rows = $db->all(
                "SELECT {$pinCol} AS pin, {$timeCol} AS t FROM {$table}
                  WHERE {$timeCol} BETWEEN :a AND :b",
                [':a' => $from, ':b' => $to]
            );
        } catch (\Throwable $e) {
            return [[], false];
        }
        $map = [];
        foreach ($rows as $r) {
            $u = strtotime((string) $r['t']);
            if ($u !== false) $map[(string) $r['pin']][] = $u;
        }
        foreach ($map as &$list) { $list = array_values(array_unique($list)); sort($list); }
        return [$map, true];
    }

    private static function tsFor(array $p, array $byPin): array
    {
        $cands = [pin_from_code((string) ($p['emp_id'] ?? '')), $p['emp_id'] ?? null, $p['emp_code'] ?? null];
        $ts = [];
        foreach ($cands as $c) {
            if ($c !== null && $c !== '' && isset($byPin[(string) $c])) {
                $ts = array_merge($ts, $byPin[(string) $c]);
            }
        }
        $ts = array_values(array_unique($ts));
        sort($ts);
        return $ts;
    }

    private static function sched(array $p): array
    {
        $t = fn($detail, $shift) => ($detail !== null && trim((string) $detail) !== '')
            ? date('H:i', strtotime((string) $detail))
            : (trim((string) ($shift ?? '')) !== '' ? trim((string) $shift) : null);
        return [
            'code'       => trim((string) ($p['code'] ?? '')),
            'first_in'   => $t($p['Intime']  ?? null, $p['FromTime']  ?? null),
            'first_out'  => $t($p['Outtime'] ?? null, $p['ToTime']    ?? null),
            'second_in'  => $t($p['InTime1'] ?? null, $p['FromTime1'] ?? null),
            'second_out' => $t($p['OutTime1']?? null, $p['ToTime1']   ?? null),
        ];
    }
}
