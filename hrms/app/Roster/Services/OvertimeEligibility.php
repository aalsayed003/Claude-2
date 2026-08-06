<?php
namespace App\Roster\Services;

use App\Core\Database;
use App\Core\Config;
use App\Roster\Repositories\AttendanceRepository;
use App\Roster\Repositories\RosterRepository;

/**
 * Derives OT-eligible days for one employee over a cutoff period (legacy mode),
 * straight from the roster (AllotShiftDetail) and the raw punches (checkinout),
 * using the same schedule-aware pairing as View Attendance.
 *
 * The eligibility RULE (mirrors AttendanceEngine::applyShiftMetrics, which is
 * what the clean-schema `attendance.ot_early_min/ot_late_min` columns store):
 *
 *   Working day  — early-punch-in OT  = you clocked IN earlier than the shift's
 *                  scheduled start; late-punch-out OT = you clocked OUT later
 *                  than the shift's scheduled end. Each counts only once it is
 *                  at least `attendance.ot_min_threshold` minutes, and the FULL
 *                  early/late minutes are reported (not the excess over the floor).
 *
 *   Off day / PH — a day rostered DAY OFF or PUBLIC HOLIDAY on which the employee
 *                  actually punched: the whole worked duration is claimable OT.
 *
 * Shifts listed in `legacy.ot_exclude_shift_ids` are skipped (e.g. shifts that
 * never accrue overtime).
 */
class OvertimeEligibility
{
    /**
     * @return array<int,array{work_date:string,day_type:string,shift_code:string,
     *   ot_early_min:int,ot_late_min:int,worked_min:int}>
     */
    public static function forEmployee(Database $db, array $emp, int $empId, string $from, string $to): array
    {
        $threshold = (int) Config::get('attendance.ot_min_threshold', 60);
        $exclude   = array_map('intval', (array) Config::get('legacy.ot_exclude_shift_ids', []));

        $scheduled = (new RosterRepository($db))->forEmployeeRange($empId, $from, $to);

        $repo = new AttendanceRepository($db);
        $pins = [
            pin_from_code((string) ($emp['emp_id'] ?? '')),
            $emp['emp_id'] ?? null,
            $emp['emp_code'] ?? null,
        ];
        // Prefer the raw punch feed (schedule-aware pairing). If that table isn't
        // reachable (e.g. punch_table not pointed at the biometric DB), fall back
        // to the pre-paired Atten_MMYYYY tables — exactly like View Attendance —
        // so OT is never silently empty just because the raw feed is unavailable.
        $raw = $repo->rawPunches(
            $pins,
            $from . ' 00:00:00',
            date('Y-m-d', strtotime($to . ' +1 day')) . ' 12:00:00'
        );
        $useRaw = $raw !== null;
        $atten  = $useRaw ? [] : $repo->forEmployee([$emp['emp_id'] ?? null, $emp['emp_code'] ?? null], $from, $to);

        $available = $raw ?? [];
        $out = [];
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $s = $scheduled[$date] ?? null;

            if ($useRaw) {
                $a = PunchPairer::pair($date, $s, $available);
                if (!empty($a['used_ts'])) {
                    $available = array_values(array_diff($available, $a['used_ts']));
                }
            } else {
                $a = $atten[$date] ?? null;
            }
            if (!$a || ($a['punch_count'] ?? 0) === 0) {
                continue;   // nothing punched -> nothing to claim
            }

            $code = strtoupper((string) ($s['code'] ?? ''));
            $isOff = ($s === null) || $code === 'DAY OFF' || str_contains($code, 'HOLIDAY');

            if ($isOff) {
                // Off day / public holiday actually worked: the whole worked span
                // is claimable. Use first-in .. last-out as the worked duration.
                $in  = $a['act_first_in'] ? strtotime($a['act_first_in']) : null;
                $lastOut = $a['act_second_out'] ?: $a['act_first_out'];
                $out2 = $lastOut ? strtotime($lastOut) : null;
                $worked = ($in && $out2 && $out2 > $in) ? (int) round(($out2 - $in) / 60) : 0;
                if ($worked <= 0) {
                    continue;
                }
                $out[] = [
                    'work_date'    => $date,
                    'day_type'     => 'off',
                    'shift_code'   => $s['code'] ?? 'OFF',
                    'ot_early_min' => 0,
                    'ot_late_min'  => 0,
                    'worked_min'   => $worked,
                ];
                continue;
            }

            if (in_array((int) ($s['shift_id'] ?? 0), $exclude, true)) {
                continue;   // this shift never accrues OT
            }

            [$earlyIn, $lateOut] = self::workingDayOt($date, $s, $a, $threshold);
            if ($earlyIn <= 0 && $lateOut <= 0) {
                continue;
            }
            $out[] = [
                'work_date'    => $date,
                'day_type'     => 'working',
                'shift_code'   => $s['code'] ?? '',
                'ot_early_min' => $earlyIn,
                'ot_late_min'  => $lateOut,
                'worked_min'   => 0,
            ];
        }
        return $out;
    }

    /**
     * Early-in and late-out OT minutes for a working day: full minutes clocked in
     * before the scheduled start / out after the scheduled end, once each is at
     * least $threshold. Scheduled boundaries are rolled across midnight so an
     * overnight shift's late-out is measured correctly.
     */
    private static function workingDayOt(string $date, array $s, array $a, int $threshold): array
    {
        $schedIn  = ($s['first_in'] ?? null) ? strtotime($date . ' ' . $s['first_in']) : null;
        $schedOutT = $s['second_out'] ?: $s['first_out'];
        $schedOut = $schedOutT ? strtotime($date . ' ' . $schedOutT) : null;
        if ($schedIn && $schedOut && $schedOut <= $schedIn) {
            $schedOut = strtotime('+1 day', $schedOut);   // night shift
        }

        $actIn  = $a['act_first_in'] ? strtotime($a['act_first_in']) : null;
        $actOut = $a['act_second_out'] ?: $a['act_first_out'];
        $actOut = $actOut ? strtotime($actOut) : null;

        $earlyIn = 0;
        if ($schedIn && $actIn) {
            $d = ($schedIn - $actIn) / 60;   // positive when clocked in early
            if ($d >= $threshold) $earlyIn = (int) round($d);
        }
        $lateOut = 0;
        if ($schedOut && $actOut) {
            $d = ($actOut - $schedOut) / 60;   // positive when clocked out late
            if ($d >= $threshold) $lateOut = (int) round($d);
        }
        return [$earlyIn, $lateOut];
    }
}
