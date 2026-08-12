<?php
/**
 * Guarantees payroll's late-in / early-out equals what the "View Attendance"
 * screen shows, because payroll now delegates to the same builder
 * (AttendanceView::legacyRows). This is the fix for payslip deductions that
 * disagreed with the Attendance screen.
 *
 * Compares, over the same employee + range: the screen's per-day late/early
 * (legacyRows) vs payroll's aggregate (PayrollAttendance::summarize).
 *
 * Run: HRMS_TEST_DB=... php tests/payroll_screen_match_check.php
 */
require __DIR__ . '/../app/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;
use App\Payroll\Services\PayrollAttendance;
use App\Roster\Services\AttendanceView;

$db = Database::app();
$fail = 0;
$ok = function (string $l, bool $c, string $x = '') use (&$fail) {
    echo ($c ? "  ok    " : "  FAIL  ") . str_pad($l, 46) . " $x\n"; if (!$c) $fail++;
};

$emp = ['id' => 202, 'emp_code' => '03002', 'emp_id' => '03002'];
$from = '2026-07-16';
$to   = '2026-07-20';

// Screen: sum the per-day late/early it displays.
$rows = AttendanceView::legacyRows($db, $emp, 202, $from, $to);
$screenLate = $screenEarly = 0;
foreach ($rows as $r) { $screenLate += (int) $r['late_in_min']; $screenEarly += (int) $r['early_out_min']; }

// Payroll: aggregate from summarize (goes through the same builder now).
$att = new PayrollAttendance($db);
$att->load($from, $to);
$s = $att->summarize($emp);

$ok('payroll present > 0 (data exercised)', $s['present_days'] > 0, "present={$s['present_days']}");
$ok('late minutes match the screen', (int) $s['late_minutes'] === $screenLate,
    "payroll={$s['late_minutes']} screen={$screenLate}");
$ok('early-out minutes match the screen', (int) $s['undertime_minutes'] === $screenEarly,
    "payroll={$s['undertime_minutes']} screen={$screenEarly}");

// And the per-day audit the payslip shows uses the same actual times.
$detailByDate = [];
foreach ($s['late_detail'] as $d) $detailByDate[$d['date']] = $d;
$rowsByDate = [];
foreach ($rows as $r) $rowsByDate[$r['work_date']] = $r;
$timesMatch = true;
foreach ($detailByDate as $date => $d) {
    $r = $rowsByDate[$date] ?? null;
    if (!$r) continue;
    $screenIn  = $r['act_first_in']  ? date('H:i', strtotime($r['act_first_in']))  : null;
    $screenOut = ($r['act_second_out'] ?: $r['act_first_out'])
        ? date('H:i', strtotime($r['act_second_out'] ?: $r['act_first_out'])) : null;
    if ($d['act_in'] !== $screenIn || $d['act_out'] !== $screenOut) $timesMatch = false;
}
$ok('payslip actual times match the screen', $timesMatch);

echo "\n" . ($fail ? "SCREEN-MATCH CHECK: $fail FAILED\n" : "SCREEN-MATCH CHECK: ALL PASSED\n");
exit($fail ? 1 : 0);
