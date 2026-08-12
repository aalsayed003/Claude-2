<?php
/**
 * Regression: payroll late-in / early-out must equal what the Attendance screen
 * shows — i.e. the schedule-aware pairer's own numbers (with its 15-min grace),
 * not a naive re-computation with zero grace. Also checks the per-day audit trail.
 *
 * Injects roster + paired punches directly (no DB pipeline) and runs summarize().
 *
 * Run:  HRMS_TEST_DB=... php tests/payroll_lateearly_check.php
 */
require __DIR__ . '/../app/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;
use App\Payroll\Services\PayrollAttendance;

$att = new PayrollAttendance(Database::app());
$r = new ReflectionClass($att);
$set = function (string $prop, $val) use ($r, $att) { $p = $r->getProperty($prop); $p->setAccessible(true); $p->setValue($att, $val); };

$shift = ['shift_id' => 1, 'name' => 'DAY', 'first_in' => '08:00', 'first_out' => '17:30',
          'second_in' => null, 'second_out' => null, 'total_hours' => 9];
$set('from', '2026-07-01'); $set('to', '2026-07-03'); $set('linked', true);
$set('roster', [5001 => ['2026-07-01' => $shift, '2026-07-02' => $shift, '2026-07-03' => $shift]]);
$pair = fn($in, $out, $late, $early, $cnt, $odd = 0) => [
    'first_in' => $in, 'first_out' => $out, 'second_in' => null, 'second_out' => null,
    'punch_count' => $cnt, 'is_odd_punch' => $odd,
    'late_in_min' => $late, 'early_out_min' => $early, 'from_pairer' => true];
$set('attendance', ['E5001' => [
    // 10 min late but WITHIN the pairer's 15-min grace -> pairer says 0 -> payroll must say 0
    '2026-07-01' => $pair('2026-07-01 08:10:00', '2026-07-01 17:33:00', 0, 0, 2),
    // 40 late / 30 early -> charged
    '2026-07-02' => $pair('2026-07-02 08:40:00', '2026-07-02 17:00:00', 40, 30, 2),
    // odd punch (missing out) -> no charge but flagged for correction
    '2026-07-03' => $pair('2026-07-03 08:10:00', null, 0, 0, 1, 1),
]]);

$s = $att->summarize(['id' => 5001, 'emp_code' => 'E5001']);

$fail = 0;
$ok = function (string $l, bool $c, string $x = '') use (&$fail) {
    echo ($c ? "  ok    " : "  FAIL  ") . str_pad($l, 50) . " $x\n"; if (!$c) $fail++;
};

$ok('present 3 days', $s['present_days'] === 3);
$ok('10-min late within grace NOT charged (matches screen)', $s['late_days'] === 1 && $s['late_minutes'] === 40,
    "late_days={$s['late_days']} late_min={$s['late_minutes']}");
$ok('early-out uses pairer number (30)', $s['undertime_minutes'] === 30 && $s['undertime_days'] === 1);
$detail = $s['late_detail'];
$ok('audit lists the 2 relevant days', count($detail) === 2, 'rows=' . count($detail));
$byDate = [];
foreach ($detail as $d) $byDate[$d['date']] = $d;
$ok('07-01 (within grace) not in audit', !isset($byDate['2026-07-01']));
$ok('07-02 shows 40 late / 30 early with times',
    ($byDate['2026-07-02']['late'] ?? 0) === 40 && ($byDate['2026-07-02']['early'] ?? 0) === 30
    && ($byDate['2026-07-02']['act_in'] ?? '') === '08:40' && ($byDate['2026-07-02']['act_out'] ?? '') === '17:00');
$ok('07-03 flagged as odd punch, no charge',
    ($byDate['2026-07-03']['odd'] ?? 0) === 1 && ($byDate['2026-07-03']['late'] ?? -1) === 0
    && ($byDate['2026-07-03']['early'] ?? -1) === 0);

echo "\n" . ($fail ? "LATE/EARLY CHECK: $fail FAILED\n" : "LATE/EARLY CHECK: ALL PASSED\n");
exit($fail ? 1 : 0);
