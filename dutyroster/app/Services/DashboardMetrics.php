<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Config;
use App\Repositories\AttendanceRepository;

/**
 * Dashboard "today" counts derived from the SAME roster + raw-punch pairing as
 * View Attendance, so the dashboard can't disagree with the attendance page —
 * but computed in BULK: one roster query + one day-bounded punch query, then
 * paired in memory. (The earlier version ran a full per-employee attendance
 * query — including a cross-database punch scan — for every rostered person,
 * which made the landing page very slow.)
 */
class DashboardMetrics
{
    /**
     * @return array{absent:int,odd_punch:int,late:int,early:int,day_off:int}
     */
    public static function todayCounts(Database $db, string $date): array
    {
        $counts = ['absent' => 0, 'odd_punch' => 0, 'late' => 0, 'early' => 0, 'day_off' => 0];

        $hdr = lt('roster_hdr');
        $dtl = lt('roster_dtl');
        $emp = lt('employee');
        $shift = lt('shift');

        // 1) One query: everyone rostered today, with resolved shift times + code.
        $people = $db->all(
            "SELECT h.Empid AS id, e.EmployeeId AS emp_id, e.EmpCode AS emp_code, s.Name AS code,
                    d.Intime, d.Outtime, d.InTime1, d.OutTime1,
                    s.FromTime, s.ToTime, s.FromTime1, s.ToTime1
               FROM {$dtl} d
               JOIN {$hdr} h ON h.ID = d.AllotId AND h.Deleted = 0
               JOIN {$emp} e ON e.ID = h.Empid
               JOIN {$shift} s ON s.ID = d.Shiftid
              WHERE d.Deleted = 0 AND d.ShiftDate BETWEEN :a AND :b",
            [':a' => $date, ':b' => $date . ' 23:59:59']
        );
        if (!$people) {
            return $counts;
        }

        // 2) One day-bounded query for ALL punches (so even a cross-DB view scan
        //    is limited to a single day), grouped by pin in memory. If the punch
        //    source isn't reachable, only day-off is counted (don't mark everyone
        //    absent just because the feed is down).
        [$byPin, $havePunches] = self::punchesByPin($db, $date);

        foreach ($people as $p) {
            $sched = self::sched($p);
            $code  = strtoupper((string) ($sched['code'] ?? ''));
            $isOff = $code === 'DAY OFF' || str_contains($code, 'HOLIDAY');
            if ($isOff) { $counts['day_off']++; continue; }
            if (!$havePunches) { continue; }

            $ts = self::tsFor($p, $byPin);
            $a  = PunchPairer::pair($date, $sched, $ts);
            $status = AttendanceRepository::deriveStatus($a['punch_count'] ?? 0, $sched);

            if ($status === 'absent') $counts['absent']++;
            if (!empty($a['is_odd_punch']))     $counts['odd_punch']++;
            if (($a['late_in_min']   ?? 0) > 0) $counts['late']++;
            if (($a['early_out_min'] ?? 0) > 0) $counts['early']++;
        }
        return $counts;
    }

    /** pin => sorted unix timestamps for the day window; [map, sourceReachable]. */
    private static function punchesByPin(Database $db, string $date): array
    {
        $table   = Config::get('legacy.punch_table', 'checkinout');
        $pinCol  = Config::get('legacy.punch_pin_col', 'pin');
        $timeCol = Config::get('legacy.punch_time_col', 'checktime');
        $from = $date . ' 00:00:00';
        $to   = date('Y-m-d', strtotime($date . ' +1 day')) . ' 12:00:00';   // catch overnight outs
        try {
            $rows = $db->all(
                "SELECT {$pinCol} AS pin, {$timeCol} AS t FROM {$table}
                  WHERE {$timeCol} BETWEEN :a AND :b",
                [':a' => $from, ':b' => $to]
            );
        } catch (\Throwable $e) {
            return [[], false];   // punch source not reachable
        }
        $map = [];
        foreach ($rows as $r) {
            $u = strtotime((string) $r['t']);
            if ($u !== false) $map[(string) $r['pin']][] = $u;
        }
        foreach ($map as &$list) { $list = array_values(array_unique($list)); sort($list); }
        return [$map, true];
    }

    /** Merge a person's candidate pins' timestamps (9-digit PIN + code fallbacks). */
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

    /** Resolve a roster row to scheduled times (detail overrides shift master). */
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
