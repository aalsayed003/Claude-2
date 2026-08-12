<?php
/**
 * P1 calculation-parity check for the ASSH paysheet rule:
 *   earned = structure component x No.of.Days / days-in-month.
 *
 * Golden values are real (fake-data) rows from the July-2026 FTE manual
 * paysheet, so this asserts the engine's month-days divisor + proration
 * reproduce that file without needing the workbook itself.
 *
 * Run:  php tests/payroll_proration_check.php
 */
require __DIR__ . '/../app/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Payroll\Services\PayrollEngine as PE;

$fail = 0;
$ok = function (string $label, bool $cond, string $extra = '') use (&$fail) {
    echo ($cond ? "  ok    " : "  FAIL  ") . str_pad($label, 46) . " $extra\n";
    if (!$cond) $fail++;
};

// --- days in month ---
$ok('daysInPayrollMonth Jul 2026 = 31', PE::daysInPayrollMonth('2026-07') === 31);
$ok('daysInPayrollMonth Feb 2026 = 28', PE::daysInPayrollMonth('2026-02') === 28);
$ok('daysInPayrollMonth Feb 2028 = 29', PE::daysInPayrollMonth('2028-02') === 29);

// --- proration primitive ---
$ok('prorate 300 x 15/30 = 150', PE::proratedEarning(300, 15, 30) === 150.0,
    '(' . PE::proratedEarning(300, 15, 30) . ')');
$ok('prorate 300 x 31/31 = 300', PE::proratedEarning(300, 31, 31) === 300.0);

// --- golden full-month rows from the July-2026 FTE file (days = 31) ---
// [components...], deductions (GOSI etc.), expected earned, expected net
$golden = [
    ['Emp 0001', [247.137,49.427,0,24.714,0,24.714,29.656,0,0], 4.697,  375.648, 370.951],
    ['Emp 0003', [321.435,67.903,0,26.117,0,106.877,0,0,0],      8.204,  522.332, 514.128],
    ['Emp 0002', [380.776,184.949,0,43.517,0,21.759,0,0,0],      6.435,  631.001, 624.566],
];
$days = 31; $md = PE::daysInPayrollMonth('2026-07');
foreach ($golden as [$name, $comps, $ded, $expEarn, $expNet]) {
    $earned = 0.0;
    foreach ($comps as $c) $earned += PE::proratedEarning((float) $c, $days, $md);
    $earned = money_round($earned);
    $net = money_round($earned - $ded);
    $ok("$name earned = $expEarn", abs($earned - $expEarn) < 0.001, "(app $earned)");
    $ok("$name net    = $expNet",  abs($net - $expNet) < 0.001,     "(app $net)");
}

echo "\n" . ($fail ? "PRORATION CHECK: $fail FAILED\n" : "PRORATION CHECK: ALL PASSED\n");
exit($fail ? 1 : 0);
