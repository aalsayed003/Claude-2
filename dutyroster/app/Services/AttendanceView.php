<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Config;
use App\Repositories\AttendanceRepository;
use App\Repositories\RosterRepository;
use App\Repositories\CorrectionRepository;

/**
 * Builds the "actual vs scheduled" attendance rows for one employee over a
 * date range (legacy mode). Extracted from AttendanceController so both the
 * standalone View Attendance page and the merged Attendance/Correction/
 * Change-Schedule workspace page share one implementation.
 */
class AttendanceView
{
    public static function legacyRows(Database $db, array $emp, int $empId, string $from, string $to): array
    {
        $repo      = new AttendanceRepository($db);
        $scheduled = (new RosterRepository($db))->forEmployeeRange($empId, $from, $to);

        // Prefer schedule-aware pairing straight from the raw punch feed
        // (fixes split-duty and overnight mis-pairing); fall back to the
        // pre-paired Atten_MMYYYY tables when the raw table isn't available.
        $pins = [
            pin_from_code((string) ($emp['emp_id'] ?? '')),
            $emp['emp_id'] ?? null,
            $emp['emp_code'] ?? null,
        ];
        $raw    = $repo->rawPunches($pins, $from . ' 00:00:00',
                    date('Y-m-d', strtotime($to . ' +1 day')) . ' 12:00:00');
        $useRaw = $raw !== null;
        $atten  = $useRaw ? [] : $repo->forEmployee([$emp['emp_id'] ?? null, $emp['emp_code'] ?? null], $from, $to);

        $graceLate  = (int) Config::get('attendance.grace_late_min', 15);
        $graceEarly = (int) Config::get('attendance.grace_early_min', 15);

        // Approved corrections override the computed punch with the rostered time.
        $corrections = (new CorrectionRepository($db))->appliedForEmployee($empId, $from, $to);

        $available = $raw ?? [];   // punches not yet claimed by an earlier day
        $rows = [];
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $s = $scheduled[$date] ?? null;
            if ($useRaw) {
                $a = PunchPairer::pair($date, $s, $available);
                if (!empty($a['used_ts'])) {
                    // A day's punches (incl. an overnight out after midnight) are
                    // consumed so the following day can't count them again.
                    $available = array_values(array_diff($available, $a['used_ts']));
                }
            } else {
                $a = $atten[$date] ?? null;
            }
            $a = $a ?: ['act_first_in' => null, 'act_first_out' => null, 'act_second_in' => null,
                        'act_second_out' => null, 'punch_count' => 0, 'is_odd_punch' => 0];

            // Apply an approved correction: override slots with the rostered time.
            $corr = $corrections[$date] ?? [];
            $corrected = false;
            foreach (['act_first_in', 'act_first_out', 'act_second_in', 'act_second_out'] as $slot) {
                if (!empty($corr[$slot])) { $a[$slot] = $corr[$slot]; $corrected = true; }
            }
            if ($corrected) {
                $filled = count(array_filter([$a['act_first_in'], $a['act_first_out'], $a['act_second_in'], $a['act_second_out']]));
                $a['punch_count'] = max((int) ($a['punch_count'] ?? 0), $filled);
                $a['is_odd_punch'] = 0;   // a corrected day is treated as complete
            }

            $punchCount = $a['punch_count'] ?? 0;
            if ($punchCount === 0 && $s === null) {
                continue;   // nothing scheduled and nothing punched — skip the row
            }

            $status = AttendanceRepository::deriveStatus($punchCount, $s);

            if ($useRaw) {
                // The pairer already computed late/early against the schedule.
                $late  = ($status === 'present') ? ($a['late_in_min']   ?? 0) : 0;
                $early = ($status === 'present') ? ($a['early_out_min'] ?? 0) : 0;
            } else {
                $late = $early = 0;
                if ($s && $a && $status === 'present') {
                    if ($s['first_in'] && $a['act_first_in']) {
                        $d = (strtotime($a['act_first_in']) - strtotime($date . ' ' . $s['first_in'])) / 60;
                        if ($d > $graceLate) $late = (int) round($d);
                    }
                    $schedOut = $s['second_out'] ?: $s['first_out'];
                    $actOut   = $a['act_second_out'] ?: $a['act_first_out'];
                    if ($schedOut && $actOut) {
                        $d = (strtotime($date . ' ' . $schedOut) - strtotime($actOut)) / 60;
                        if ($d > $graceEarly) $early = (int) round($d);
                    }
                }
            }

            // A corrected slot is now the rostered time, so its late/early is excused.
            if (!empty($corr['act_first_in']))  $late  = 0;
            if (!empty($corr['act_second_out']) || !empty($corr['act_first_out'])) $early = 0;

            $rows[] = [
                'work_date'      => $date,
                'act_first_in'   => $a['act_first_in']   ?? null,
                'act_first_out'  => $a['act_first_out']  ?? null,
                'act_second_in'  => $a['act_second_in']  ?? null,
                'act_second_out' => $a['act_second_out'] ?? null,
                'sch_first_in'   => $s['first_in']   ?? null,
                'sch_first_out'  => $s['first_out']  ?? null,
                'sch_second_in'  => $s['second_in']  ?? null,
                'sch_second_out' => $s['second_out'] ?? null,
                'shift_code'     => $s['code'] ?? null,
                'late_in_min'    => $late,
                'early_out_min'  => $early,
                'is_odd_punch'   => $a['is_odd_punch'] ?? 0,
                'corrected'      => $corrected ? 1 : 0,
                'status'         => $status,
            ];
        }
        return $rows;
    }
}
