<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Config;

/**
 * Turns a cutoff cycle of roster + attendance + leave + approved overtime into
 * the handful of numbers payroll actually pays on: payable days, absence,
 * unpaid leave, late/early-out minutes and overtime hours by day type.
 *
 * Everything is loaded in bulk for the whole period — six queries regardless of
 * head-count — because a run touches every employee and per-employee round
 * trips against the monthly Atten_ tables are what made the legacy month-end
 * slow.
 *
 * Day classification, in order:
 *   1. not rostered            -> unrostered (neither paid nor deducted)
 *   2. rostered as a day off   -> off day
 *   3. approved leave          -> leave (paid or unpaid by leave type)
 *   4. punches exist           -> present  (late / early-out measured)
 *   5. rostered, no punches    -> absent
 */
class PayrollAttendance
{
    private array $roster    = [];   // empId  => [date => shift]
    private array $attendance = [];  // empCode=> [date => punches]
    private array $leave     = [];   // empId  => [ [from,to,leave_id,days,…] ]
    private array $overtime  = [];   // empId  => [ [date, minutes, day_type] ]
    private array $corrections = []; // empId  => [ "date|kind" => true ]
    private array $dayOffShiftIds = [];
    private string $from = '';
    private string $to   = '';
    private bool $linked = false;

    public function __construct(private Database $db) {}

    /** Load every input for the cycle. Call once per run. */
    public function load(string $from, string $to): void
    {
        $this->from = $from;
        $this->to   = $to;

        // No Duty Roster link yet -> nothing to load; summaries assume full
        // attendance. Turning the link on (config) makes payroll attendance-driven.
        if (!RosterLink::enabled()) {
            $this->linked = false;
            return;
        }

        $this->linked = true;
        $this->dayOffShiftIds = $this->resolveDayOffShifts();
        $this->loadRoster($from, $to);
        $this->loadAttendance($from, $to);
        $this->loadLeave($from, $to);
        $this->loadOvertime($from, $to);
        $this->loadCorrections($from, $to);
    }

    /**
     * Summarise one employee. $emp needs 'id' (Employee.ID) and 'emp_code'.
     */
    public function summarize(array $emp): array
    {
        if (!$this->linked) {
            return $this->fullAttendanceSummary();
        }
        $empId   = (int) $emp['id'];
        $empCode = (string) ($emp['emp_code'] ?? '');

        $s = [
            'period_from'      => $this->from,
            'period_to'        => $this->to,
            'calendar_days'    => 0,
            'scheduled_days'   => 0,
            'scheduled_hours'  => 0.0,
            'present_days'     => 0,
            'absent_days'      => 0,
            'off_days'         => 0,
            'unrostered_days'  => 0,
            'paid_leave_days'  => 0.0,
            'unpaid_leave_days'=> 0.0,
            'leave_by_type'    => [],
            'late_minutes'     => 0,
            'late_days'        => 0,
            'undertime_minutes'=> 0,
            'undertime_days'   => 0,
            'worked_minutes'   => 0,
            'ot_minutes'       => ['normal' => 0, 'night' => 0, 'restday' => 0, 'holiday' => 0],
            'days'             => [],
        ];

        $roster  = $this->roster[$empId] ?? [];
        $punches = $this->attendance[$empCode] ?? [];
        $leaves  = $this->leave[$empId] ?? [];
        $unpaidIds = array_map('intval', (array) Config::get('payroll.unpaid_leave_ids', []));

        for ($ts = strtotime($this->from); $ts <= strtotime($this->to); $ts = strtotime('+1 day', $ts)) {
            $date = date('Y-m-d', $ts);
            $s['calendar_days']++;

            $shift = $roster[$date] ?? null;
            $punch = $punches[$date] ?? null;
            $lv    = $this->leaveOn($leaves, $date);

            if (!$shift) {
                $s['unrostered_days']++;
                $s['days'][$date] = 'unrostered';
                continue;
            }
            if ($this->isDayOff($shift)) {
                $s['off_days']++;
                $s['days'][$date] = 'day_off';
                continue;
            }

            $s['scheduled_days']++;
            $s['scheduled_hours'] += (float) ($shift['total_hours'] ?? 0);

            if ($lv) {
                $portion = (float) ($lv['portion'] ?? 1);
                $typeId  = (int) $lv['leave_id'];
                $s['leave_by_type'][$typeId] = ($s['leave_by_type'][$typeId] ?? 0) + $portion;
                if (in_array($typeId, $unpaidIds, true)) {
                    $s['unpaid_leave_days'] += $portion;
                } else {
                    $s['paid_leave_days'] += $portion;
                }
                $s['days'][$date] = 'leave';
                continue;
            }

            if (!$punch || (int) $punch['punch_count'] === 0) {
                $s['absent_days']++;
                $s['days'][$date] = 'absent';
                continue;
            }

            $s['present_days']++;
            $s['days'][$date] = 'present';
            $s['worked_minutes'] += $this->workedMinutes($punch);

            $m = $this->lateAndUndertime($date, $shift, $punch, $empId);
            if ($m['late'] > 0) {
                $s['late_minutes'] += $m['late'];
                $s['late_days']++;
            }
            if ($m['undertime'] > 0) {
                $s['undertime_minutes'] += $m['undertime'];
                $s['undertime_days']++;
            }
        }

        foreach ($this->overtime[$empId] ?? [] as $ot) {
            if ($ot['date'] < $this->from || $ot['date'] > $this->to) {
                continue;
            }
            $type = $this->otDayType($ot['date'], $s['days'][$ot['date']] ?? 'present');
            $s['ot_minutes'][$type] += $ot['minutes'];
        }
        $s['ot_minutes_total'] = array_sum($s['ot_minutes']);

        // Days the employee is entitled to be paid for: everything in the cycle
        // except absence and unpaid leave.
        $s['payable_days'] = max(0, $s['calendar_days'] - $s['absent_days'] - $s['unpaid_leave_days']);

        return $s;
    }

    /**
     * The summary used when the Duty Roster link is off: full attendance, no
     * absence/late/OT. Payable days = the whole cycle; the engine still applies
     * the mid-month employment factor from the joining/leaving dates.
     */
    private function fullAttendanceSummary(): array
    {
        $days = (int) round((strtotime($this->to) - strtotime($this->from)) / 86400) + 1;
        return [
            'period_from'      => $this->from,
            'period_to'        => $this->to,
            'calendar_days'    => $days,
            'scheduled_days'   => $days,
            'scheduled_hours'  => 0.0,
            'present_days'     => $days,
            'absent_days'      => 0,
            'off_days'         => 0,
            'unrostered_days'  => 0,
            'paid_leave_days'  => 0.0,
            'unpaid_leave_days'=> 0.0,
            'leave_by_type'    => [],
            'late_minutes'     => 0,
            'late_days'        => 0,
            'undertime_minutes'=> 0,
            'undertime_days'   => 0,
            'worked_minutes'   => 0,
            'ot_minutes'       => ['normal' => 0, 'night' => 0, 'restday' => 0, 'holiday' => 0],
            'ot_minutes_total' => 0,
            'payable_days'     => $days,
            'days'             => [],
            'no_roster_link'   => true,
        ];
    }

    // ------------------------------------------------------------- loading --

    private function loadRoster(string $from, string $to): void
    {
        $hdr = lt('roster_hdr');
        $dtl = lt('roster_dtl');
        $shf = lt('shift');
        $rows = $this->db->all(
            "SELECT h.Empid AS emp_id, d.ShiftDate AS work_date, d.Shiftid AS shift_id,
                    s.Name AS shift_name, d.TotalHours AS d_hours, s.TotalHours AS s_hours,
                    d.Intime, d.Outtime, d.InTime1, d.OutTime1,
                    s.FromTime, s.ToTime, s.FromTime1, s.ToTime1
               FROM {$dtl} d
               JOIN {$hdr} h ON h.ID = d.AllotId AND h.Deleted = 0
               JOIN {$shf} s ON s.ID = d.Shiftid
              WHERE d.Deleted = 0 AND d.ShiftDate BETWEEN :a AND :b",
            [':a' => $from, ':b' => $to . ' 23:59:59']
        );
        foreach ($rows as $r) {
            $date = substr((string) $r['work_date'], 0, 10);
            $this->roster[(int) $r['emp_id']][$date] = [
                'shift_id'    => (int) $r['shift_id'],
                'name'        => trim((string) $r['shift_name']),
                'first_in'    => $this->time($r['Intime'],   $r['FromTime']),
                'first_out'   => $this->time($r['Outtime'],  $r['ToTime']),
                'second_in'   => $this->time($r['InTime1'],  $r['FromTime1']),
                'second_out'  => $this->time($r['OutTime1'], $r['ToTime1']),
                'total_hours' => (float) ($r['d_hours'] ?? $r['s_hours'] ?? 0),
            ];
        }
    }

    /** Atten_MMYYYY is keyed by EmpCode, and a cycle always spans two tables. */
    private function loadAttendance(string $from, string $to): void
    {
        $prefix = Config::get('legacy.att_month_prefix', 'Atten_');
        $mStart = strtotime(date('Y-m-01', strtotime($from)));
        $mEnd   = strtotime(date('Y-m-01', strtotime($to)));

        for ($ts = $mStart; $ts <= $mEnd; $ts = strtotime('+1 month', $ts)) {
            $tbl = $prefix . date('mY', $ts);
            try {
                $rows = $this->db->all(
                    "SELECT Empid, Todate AS work_date, Intime, Outtime, Intime1, Outtime1
                       FROM {$tbl} WHERE Todate BETWEEN :a AND :b",
                    [':a' => $from, ':b' => $to . ' 23:59:59']
                );
            } catch (\Throwable $e) {
                continue;   // month table not created yet
            }
            foreach ($rows as $r) {
                $date = substr((string) $r['work_date'], 0, 10);
                $p = array_filter([$r['Intime'], $r['Outtime'], $r['Intime1'], $r['Outtime1']],
                                  fn($v) => $v !== null && $v !== '');
                $this->attendance[(string) $r['Empid']][$date] = [
                    'first_in'    => $r['Intime']   ?: null,
                    'first_out'   => $r['Outtime']  ?: null,
                    'second_in'   => $r['Intime1']  ?: null,
                    'second_out'  => $r['Outtime1'] ?: null,
                    'punch_count' => count($p),
                ];
            }
        }
    }

    private function loadLeave(string $from, string $to): void
    {
        $app = lt('leave_app');
        try {
            $rows = $this->db->all(
                "SELECT EmpID, LeaveID, FromDate, ToDate, Halfdaydate, halftype
                   FROM {$app}
                  WHERE (Deleted = 0 OR Deleted IS NULL) AND Approved = 1
                    AND FromDate <= :b AND ToDate >= :a",
                [':a' => $from, ':b' => $to . ' 23:59:59']
            );
        } catch (\Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $this->leave[(int) $r['EmpID']][] = [
                'leave_id'  => (int) $r['LeaveID'],
                'from'      => substr((string) $r['FromDate'], 0, 10),
                'to'        => substr((string) $r['ToDate'], 0, 10),
                'half_date' => $r['Halfdaydate'] ? substr((string) $r['Halfdaydate'], 0, 10) : null,
            ];
        }
    }

    /**
     * Only overtime that reached an approved state is paid. StartOverTime /
     * EndOverTime are used when present because they are unambiguous;
     * TotalOverTime's unit is read from config.
     */
    private function loadOvertime(string $from, string $to): void
    {
        if (!Config::get('payroll.ot_approved_only', true)) {
            return;   // caller wants raw derived OT; not supported for pay
        }
        // DR_OverTime lives in the companion DB (DB_ASSH) in production, so it
        // is qualified the same way as DR_CorrectionRequest.
        $ot     = $this->companionRef(lt('ot'));
        $states = array_map('intval', (array) Config::get('payroll.ot_paid_states', [5, 6, 14]));
        $in     = implode(',', array_map(fn($i) => (int) $i, $states)) ?: '-1';
        try {
            $rows = $this->db->all(
                "SELECT EmployeeID, OverTimeDate, StartOverTime, EndOverTime, TotalOverTime
                   FROM {$ot}
                  WHERE StateID IN ({$in})
                    AND (IsExpired IS NULL OR IsExpired = 0)
                    AND OverTimeDate BETWEEN :a AND :b",
                [':a' => $from, ':b' => $to . ' 23:59:59']
            );
        } catch (\Throwable $e) {
            $rows = [];
        }
        $unit = Config::get('payroll.ot_source_unit', 'hours');
        foreach ($rows as $r) {
            $minutes = 0;
            if (!empty($r['StartOverTime']) && !empty($r['EndOverTime'])) {
                $a = strtotime((string) $r['StartOverTime']);
                $b = strtotime((string) $r['EndOverTime']);
                if ($b < $a) { $b += 86400; }
                $minutes = (int) round(($b - $a) / 60);
            } else {
                $total = (float) $r['TotalOverTime'];
                $minutes = (int) round($unit === 'minutes' ? $total : $total * 60);
            }
            if ($minutes <= 0) {
                continue;
            }
            $this->overtime[(int) $r['EmployeeID']][] = [
                'date'    => substr((string) $r['OverTimeDate'], 0, 10),
                'minutes' => $minutes,
            ];
        }
    }

    /**
     * Approved attendance corrections waive the late-in / early-out they cover,
     * so an employee is not docked for a punch the department already fixed.
     * TypeID 0,2 = late-in; 1,3 = early-out.
     */
    private function loadCorrections(string $from, string $to): void
    {
        $ref = $this->companionRef(Config::get('legacy.correction_table', 'DR_CorrectionRequest'));
        $states = array_map('intval', (array) Config::get('payroll.correction_approved_states', [5, 6, 13, 14]));
        $in = implode(',', $states) ?: '-1';
        try {
            $rows = $this->db->all(
                "SELECT EmployeeID, DayFor, TypeID FROM {$ref}
                  WHERE StateID IN ({$in}) AND DayFor BETWEEN :a AND :b",
                [':a' => $from, ':b' => $to . ' 23:59:59']
            );
        } catch (\Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $kind = in_array((int) $r['TypeID'], [0, 2], true) ? 'late' : 'undertime';
            $date = substr((string) $r['DayFor'], 0, 10);
            $this->corrections[(int) $r['EmployeeID']][$date . '|' . $kind] = true;
        }
    }

    // ---------------------------------------------------------- day metrics --

    private function lateAndUndertime(string $date, array $shift, array $punch, int $empId): array
    {
        $graceLate  = (int) Config::get('attendance.grace_late_min', 0);
        $graceEarly = (int) Config::get('attendance.grace_early_min', 0);

        $schedIn  = $shift['first_in']  ? $date . ' ' . $shift['first_in']  . ':00' : null;
        $schedOut = ($shift['second_out'] ?: $shift['first_out'])
            ? $date . ' ' . ($shift['second_out'] ?: $shift['first_out']) . ':00' : null;
        if ($schedIn && $schedOut && strtotime($schedOut) <= strtotime($schedIn)) {
            $schedOut = date('Y-m-d H:i:s', strtotime($schedOut . ' +1 day'));   // night shift
        }

        $firstIn = $punch['first_in'];
        $lastOut = $punch['second_out'] ?: $punch['first_out'];

        $late = $under = 0;
        if ($schedIn && $firstIn) {
            $d = (strtotime((string) $firstIn) - strtotime($schedIn)) / 60;
            if ($d > $graceLate) {
                $late = (int) round($d);
            }
        }
        if ($schedOut && $lastOut) {
            $d = (strtotime($schedOut) - strtotime((string) $lastOut)) / 60;
            if ($d > $graceEarly) {
                $under = (int) round($d);
            }
        }

        if (isset($this->corrections[$empId][$date . '|late'])) {
            $late = 0;
        }
        if (isset($this->corrections[$empId][$date . '|undertime'])) {
            $under = 0;
        }
        return ['late' => $late, 'undertime' => $under];
    }

    private function workedMinutes(array $p): int
    {
        $m = 0;
        if ($p['first_in'] && $p['first_out']) {
            $m += max(0, (strtotime((string) $p['first_out']) - strtotime((string) $p['first_in'])) / 60);
        }
        if ($p['second_in'] && $p['second_out']) {
            $m += max(0, (strtotime((string) $p['second_out']) - strtotime((string) $p['second_in'])) / 60);
        }
        return (int) round($m);
    }

    /** OT on a rostered day off or public holiday is paid at the higher rate. */
    private function otDayType(string $date, string $dayStatus): string
    {
        if ($dayStatus === 'day_off' || $dayStatus === 'unrostered') {
            return 'restday';
        }
        if (in_array($date, $this->publicHolidays(), true)) {
            return 'holiday';
        }
        return 'normal';
    }

    private function publicHolidays(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = array_map(
                fn($d) => substr((string) $d, 0, 10),
                (array) Config::get('payroll.public_holidays', [])
            );
        }
        return $cache;
    }

    private function leaveOn(array $leaves, string $date): ?array
    {
        foreach ($leaves as $l) {
            if ($date >= $l['from'] && $date <= $l['to']) {
                return $l + ['portion' => ($l['half_date'] === $date ? 0.5 : 1)];
            }
        }
        return null;
    }

    private function isDayOff(array $shift): bool
    {
        if (in_array($shift['shift_id'], $this->dayOffShiftIds, true)) {
            return true;
        }
        $name = strtoupper($shift['name']);
        return str_contains($name, 'DAY OFF') || str_contains($name, 'WEEK OFF') || $name === 'OFF';
    }

    /**
     * Shifts that mean "not working": those configured explicitly, plus every
     * shift the leave master maps to (leave.ShiftID).
     */
    private function resolveDayOffShifts(): array
    {
        $ids = array_map('intval', (array) Config::get('payroll.day_off_shift_ids', []));
        try {
            $rows = $this->db->all(
                "SELECT DISTINCT ShiftID FROM " . lt('leave') . " WHERE ShiftID IS NOT NULL AND Deleted = 0");
            foreach ($rows as $r) {
                $ids[] = (int) $r['ShiftID'];
            }
        } catch (\Throwable $e) {
            // leave master unavailable — configured ids only
        }
        return array_values(array_unique($ids));
    }

    private function time($detail, $shiftDefault): ?string
    {
        if ($detail !== null && $detail !== '') {
            return date('H:i', strtotime((string) $detail));
        }
        $s = trim((string) ($shiftDefault ?? ''));
        return $s !== '' ? substr($s, 0, 5) : null;
    }

    /**
     * Qualify a companion-database table. The DR_* tables (DR_OverTime,
     * DR_CorrectionRequest) live in DB_ASSH in production, so with
     * legacy.companion_db = 'DB_ASSH' this returns 'DB_ASSH.dbo.DR_OverTime'.
     * When companion_db is '' (e.g. the consolidated TestASSH), the bare name
     * resolves inside the main database.
     */
    private function companionRef(string $table): string
    {
        $db = (string) Config::get('legacy.companion_db', '');
        return $db !== '' ? "{$db}.dbo.{$table}" : $table;
    }
}
