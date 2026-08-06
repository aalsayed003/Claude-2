<?php
/**
 * Proves the merged app's payroll reads the REAL punch in/out (via the roster
 * link + PunchPairer), not assumed full attendance. Drives PayrollAttendance
 * directly against the combined test DB for Nurse Mona (202), who has split-duty,
 * an off-day worked, and a late arrival in the cycle.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
putenv('HRMS_TEST_DB=/tmp/claude-0/-home-user-Claude-2/90485413-cbc0-5afd-b5d1-c26f8d1788b8/scratchpad/hrms/test.sqlite');
require '/home/user/Claude-2/hrms/app/bootstrap.php';
use App\Core\Database;
use App\Payroll\Services\PayrollAttendance;
use App\Payroll\Services\RosterLink;

$db = Database::app();
$bad = false;
function ck($c,$m,$g=''){ global $bad; printf("  %s  %s%s\n",$c?'PASS':'FAIL',$m,$c?'':"  (got: $g)"); if(!$c)$bad=true; }

echo "== Payroll reads real attendance (roster link) ==\n";
ck(RosterLink::enabled(), "roster_link is ENABLED in the merged config");

$pa = new PayrollAttendance($db);
$pa->load('2026-07-16', '2026-08-15');            // payroll month 2026-08 cutoff
$s = $pa->summarize(['id' => 202, 'emp_code' => '03002']);

ck(empty($s['no_roster_link']), "attendance is roster-driven (not the full-attendance fallback)");
ck($s['present_days'] >= 3, "present days derived from real punches (>=3)", $s['present_days']);
ck($s['late_days']   >= 1, "late arrival detected from punches (20 Jul)", $s['late_days']);
ck($s['worked_minutes'] > 0, "worked minutes computed from punch pairs", $s['worked_minutes']);
printf("   summary: present=%d absent=%d off=%d late_days=%d worked_min=%d payable=%.1f\n",
    $s['present_days'],$s['absent_days'],$s['off_days'],$s['late_days'],$s['worked_minutes'],$s['payable_days']);

echo "\n" . ($bad ? "PAYROLL-ATTENDANCE CHECK FAILED\n" : "PAYROLL READS REAL PUNCHES — PASS\n");
exit($bad ? 1 : 0);
