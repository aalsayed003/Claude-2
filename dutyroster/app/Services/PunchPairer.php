<?php
namespace App\Services;

use App\Core\Config;

/**
 * Pairs raw biometric punches to a day's scheduled shift.
 *
 * The legacy Atten_MMYYYY tables pre-pair punches per CALENDAR day, which
 * mis-handles two cases the roster knows how to resolve:
 *   - Split duty: a missing/late middle punch shifts everything, so the second
 *     in/out slots come out wrong.
 *   - Overnight shift: the out punch falls after midnight, so a per-day pairing
 *     files it as the NEXT day's in — leaving both days with a "missing" punch.
 *
 * This pairs against the schedule instead: each scheduled boundary
 * (first in/out, second in/out) is matched to its nearest punch within a
 * window, with the boundaries made monotonic across midnight so an overnight
 * out is correctly attributed to the shift that started the day before.
 */
class PunchPairer
{
    private const WINDOW_SECONDS = 21600;   // ±6h around the scheduled boundaries
    private const SLOTS = ['act_first_in', 'act_first_out', 'act_second_in', 'act_second_out'];
    private const SCHED = [
        'act_first_in' => 'first_in', 'act_first_out' => 'first_out',
        'act_second_in' => 'second_in', 'act_second_out' => 'second_out',
    ];

    /**
     * @param string     $date    the roster day, 'Y-m-d'
     * @param array|null $sched   ['first_in'=>'08:00','first_out'=>...,'second_in'=>...,'second_out'=>...]
     * @param int[]      $punchTs sorted unix timestamps of the employee's raw punches
     * @return array{act_first_in:?string,act_first_out:?string,act_second_in:?string,
     *               act_second_out:?string,punch_count:int,is_odd_punch:int,
     *               late_in_min:int,early_out_min:int}
     */
    public static function pair(string $date, ?array $sched, array $punchTs): array
    {
        $out = [
            'act_first_in' => null, 'act_first_out' => null,
            'act_second_in' => null, 'act_second_out' => null,
            'punch_count' => 0, 'is_odd_punch' => 0,
            'late_in_min' => 0, 'early_out_min' => 0,
            'used_ts' => [],   // timestamps this day claimed (so the next day won't reuse them)
        ];

        // Scheduled boundary datetimes, made monotonic so a later time-of-day
        // that is earlier on the clock rolls to the next day (overnight).
        $bnd = [];
        $prev = null;
        foreach (self::SLOTS as $slot) {
            $t = $sched[self::SCHED[$slot]] ?? null;
            if ($t === null || trim((string) $t) === '') {
                continue;
            }
            $ts = strtotime($date . ' ' . substr((string) $t, 0, 8));
            if ($ts === false) {
                continue;
            }
            if ($prev !== null) {
                while ($ts < $prev) $ts += 86400;
            }
            $bnd[$slot] = $ts;
            $prev = $ts;
        }

        if (!$bnd) {
            // No schedule: pair within this calendar day only, first-in / last-out.
            $dayStart = strtotime($date . ' 00:00:00');
            $dayEnd   = $dayStart + 86399;
            $dayPunches = array_values(array_filter($punchTs, fn($p) => $p >= $dayStart && $p <= $dayEnd));
            return self::simple($dayPunches, $out);
        }

        // Candidate punches: those inside the window around the scheduled shift.
        $first = min($bnd);
        $last  = max($bnd);
        $cand = array_values(array_filter(
            $punchTs,
            fn($p) => $p >= $first - self::WINDOW_SECONDS && $p <= $last + self::WINDOW_SECONDS
        ));

        // Greedy nearest assignment: each boundary takes its closest free punch.
        $pairs = [];
        foreach ($bnd as $slot => $bt) {
            foreach ($cand as $pi => $pt) {
                $pairs[] = [abs($pt - $bt), $slot, $pi];
            }
        }
        usort($pairs, fn($x, $y) => $x[0] <=> $y[0]);
        $usedSlot = $usedPunch = [];
        foreach ($pairs as [$dist, $slot, $pi]) {
            if (isset($usedSlot[$slot]) || isset($usedPunch[$pi])) {
                continue;
            }
            $usedSlot[$slot] = 1;
            $usedPunch[$pi]  = 1;
            $out[$slot] = date('Y-m-d H:i:s', $cand[$pi]);
            $out['used_ts'][] = $cand[$pi];
        }

        $out['punch_count']  = count($cand);
        $out['is_odd_punch'] = (count($cand) % 2 === 1) ? 1 : 0;
        self::metrics($out, $bnd);
        return $out;
    }

    /** Late-in / early-out against the (overnight-adjusted) scheduled boundaries. */
    private static function metrics(array &$out, array $bnd): void
    {
        $graceLate  = (int) Config::get('attendance.grace_late_min', 15);
        $graceEarly = (int) Config::get('attendance.grace_early_min', 15);

        $schedIn  = $bnd['act_first_in'] ?? null;
        $schedOut = $bnd['act_second_out'] ?? ($bnd['act_first_out'] ?? null);
        $actIn    = $out['act_first_in'] ? strtotime($out['act_first_in']) : null;
        $actOut   = ($out['act_second_out'] ?: $out['act_first_out']);
        $actOut   = $actOut ? strtotime($actOut) : null;

        if ($schedIn && $actIn) {
            $d = ($actIn - $schedIn) / 60;
            if ($d > $graceLate) $out['late_in_min'] = (int) round($d);
        }
        if ($schedOut && $actOut) {
            $d = ($schedOut - $actOut) / 60;
            if ($d > $graceEarly) $out['early_out_min'] = (int) round($d);
        }
    }

    /** No schedule: first punch = in, last = out. */
    private static function simple(array $punchTs, array $out): array
    {
        $punchTs = array_values($punchTs);
        $n = count($punchTs);
        if ($n >= 1) { $out['act_first_in']  = date('Y-m-d H:i:s', $punchTs[0]);     $out['used_ts'][] = $punchTs[0]; }
        if ($n >= 2) { $out['act_first_out'] = date('Y-m-d H:i:s', $punchTs[$n - 1]); $out['used_ts'][] = $punchTs[$n - 1]; }
        $out['punch_count']  = $n;
        $out['is_odd_punch'] = ($n % 2 === 1) ? 1 : 0;
        return $out;
    }
}
