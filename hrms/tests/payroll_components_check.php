<?php
/**
 * P2 component-model check: the engine applies the right FTE vs PTE component
 * set, including input-driven monthly deductions/refunds, honouring each
 * component's profile.
 *
 * Needs the combined test DB (build_hrms_db.php seeds MonthlyAllowances rows
 * for empId 9001=FTE and 9002=PTE) and the test config's part_time_categories=[9].
 *
 * Run:  HRMS_TEST_DB=... php tests/payroll_components_check.php
 */
require __DIR__ . '/../app/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;
use App\Payroll\Services\PayrollEngine;

$engine = new PayrollEngine(Database::app());
$fail = 0;
$ok = function (string $label, bool $cond, string $extra = '') use (&$fail) {
    echo ($cond ? "  ok    " : "  FAIL  ") . str_pad($label, 52) . " $extra\n";
    if (!$cond) $fail++;
};

$month = '2026-07-01';
$summary = [
    'absent_days' => 0, 'unpaid_leave_days' => 0, 'late_minutes' => 0,
    'undertime_minutes' => 0, 'ot_minutes' => [], 'present_days' => 31,
    'paid_leave_days' => 0, 'off_days' => 0, 'scheduled_days' => 31, 'calendar_days' => 31,
    'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
];

// ---- FTE employee (category 0 -> default fte) ----
$fteEmp = ['id' => 9001, 'category_id' => 0, 'department_id' => 1, 'joined_at' => null, 'left_at' => null];
$fteStruct = ['BasicSalary' => 300, 'HRA' => 100, 'GeneralAllowance' => 40,
              'ShiftOnCallAllowance' => 20, 'HodAllowance' => 99, 'TicketAllowance' => 99];
$fte = $engine->computeEmployee($fteEmp, $fteStruct, $summary, $month, null, []);
$fc = $fte['components'];
$ok('FTE resolves as fte', $engine->empType($fteEmp) === 'fte');
$ok('FTE has General + Shift&on-Call', isset($fc['general'], $fc['shift_oncall']));
$ok('FTE excludes HOD + Ticket (PTE-only)', !isset($fc['hod_allow']) && !isset($fc['ticket']));
$ok('FTE has EWA + Housing recovery (monthly ded)', isset($fc['ewa'], $fc['housing_recovery']),
    'ewa=' . ($fc['ewa'] ?? '-') . ' housing=' . ($fc['housing_recovery'] ?? '-'));
$ok('FTE has Refund (monthly earning)', isset($fc['refund']) && $fc['refund'] == 100.0);
$ok('FTE excludes NHRA/LMRA/Medical', !isset($fc['nhra']) && !isset($fc['lmra']) && !isset($fc['medical']));

// ---- PTE employee (category 9 -> pte) ----
$pteEmp = ['id' => 9002, 'category_id' => 9, 'department_id' => 1, 'joined_at' => null, 'left_at' => null];
$pteStruct = ['BasicSalary' => 200, 'GeneralAllowance' => 30, 'HodAllowance' => 50, 'TicketAllowance' => 80];
$pte = $engine->computeEmployee($pteEmp, $pteStruct, $summary, $month, null, []);
$pc = $pte['components'];
$ok('PTE resolves as pte', $engine->empType($pteEmp) === 'pte');
$ok('PTE has HOD + Ticket', isset($pc['hod_allow'], $pc['ticket']) && $pc['hod_allow'] == 50.0 && $pc['ticket'] == 80.0);
$ok('PTE has General (shared)', isset($pc['general']));
$ok('PTE has NHRA/LMRA/Medical/CPR-LMRA', isset($pc['nhra'], $pc['lmra'], $pc['medical'], $pc['cpr_lmra']),
    'nhra=' . ($pc['nhra'] ?? '-') . ' lmra=' . ($pc['lmra'] ?? '-'));
$ok('PTE has Attendance Refund (earning)', isset($pc['attendance_refund']) && $pc['attendance_refund'] == 30.0);

// ---- classification: earnings add, deductions subtract ----
$cfg = \App\Core\Config::get('payroll.components');
$dedOK = ($cfg['nhra']['type'] === 'deduction' && $cfg['refund']['type'] === 'earning'
        && $cfg['ewa']['type'] === 'deduction' && $cfg['attendance_refund']['type'] === 'earning');
$ok('types: refund/att-refund=earning, ewa/nhra=deduction', $dedOK);
// PTE net = earnings - deductions, deductions must include the govt fees
$pteDed = $pte['totals']['deductions'];
$ok('PTE deductions include monthly fees (>= 64)', $pteDed >= 64.0, '(' . $pteDed . ')');

echo "\n" . ($fail ? "COMPONENT CHECK: $fail FAILED\n" : "COMPONENT CHECK: ALL PASSED\n");
exit($fail ? 1 : 0);
