<?php
/**
 * P3 bank-file check: Bank of Payment register + QA + per-bank transfer files.
 * Self-seeds a few employees/register/statutory rows (works on sqlite and real
 * SQL Server) then asserts grouping, ROUNDDOWN net, and the QA verdicts.
 *
 * Run:  [HRMS_MSSQL=1] HRMS_TEST_DB=... php tests/payroll_bankfile_check.php
 */
require __DIR__ . '/../app/bootstrap.php';
error_reporting(E_ALL & ~E_DEPRECATED);

use App\Core\Database;
use App\Payroll\Services\BankFile;

$db = Database::app();
$fail = 0;
$ok = function (string $l, bool $c, string $x = '') use (&$fail) {
    echo ($c ? "  ok    " : "  FAIL  ") . str_pad($l, 50) . " $x\n";
    if (!$c) $fail++;
};

$M = '2026-07-01';
// clean prior test rows
foreach (['9101','9102','9103','9104','9105','9106'] as $id) {
    $db->run("DELETE FROM " . lt('employee') . " WHERE ID = :i", [':i' => $id]);
    $db->run("DELETE FROM CurrentMonth WHERE Empid = :i", [':i' => $id]);
    $db->run("DELETE FROM " . pt('statutory') . " WHERE EmployeeID = :i", [':i' => $id]);
}
// [id, name, iban, mode, net, earnings, ded]
$people = [
    [9101, 'Employee NBB',   'BH67NBOB00000123456789', 1, 370.9518, 375.6488, 4.697],  // NBB, truncates
    [9102, 'Employee KHCB',  'BH29KHCB00000000000009', 1, 500.000,  520.000,  20.000],  // KHCB
    [9103, 'Employee BBK',   'BH02BBKU00000000000010', 1, 250.2509, 260.2509, 10.000],  // BBK, truncates
    [9104, 'Employee BadIB', 'BH00BADX99',             1, 100.000,  100.000,  0.000],    // bad IBAN -> exception
    [9105, 'Employee NoIB',  '',                        1, 90.000,   90.000,   0.000],    // no IBAN -> exception
    [9106, 'Employee Mismat','BH67NBOB00000999999999', 1, 100.000,  200.000,  50.000],   // valid but net != E-D
];
foreach ($people as [$id, $name, $iban, $mode, $net, $earn, $ded]) {
    $db->insert(lt('employee'), ['ID' => $id, 'EmpCode' => (string) $id, 'Name' => $name,
        'FirstName' => $name, 'Middlename' => '', 'DesignationId' => 1,
        'StartDateTime' => '2020-01-01', 'DepartmentId' => 1, 'Deleted' => 0]);
    $db->insert('CurrentMonth', ['Empid' => $id, 'CurrentMonth' => $M, 'Deleted' => 0,
        'NetPayment' => $net, 'TotalEarnings' => $earn, 'TotalDeduction' => $ded,
        'Departmentid' => 1, 'EmployeeName' => $name]);
    $db->insert(pt('statutory'), ['EmployeeID' => $id, 'IBAN' => $iban, 'PaymentMode' => $mode, 'BankID' => 1, 'CPR' => '123']);
}

$run = ['PayrollMonth' => $M, 'RunID' => 999, 'StateID' => 3];
$bf  = new BankFile($db);

$ok('ibanBankCode reads chars 5-8', $bf->ibanBankCode('BH67NBOB00000123456789') === 'NBOB');

$reg = $bf->register($run);
$byId = [];
foreach ($reg['rows'] as $r) $byId[$r['emp_id']] = $r;

$ok('4 valid, 2 exceptions', $reg['valid'] === 4 && count($reg['exceptions']) === 2,
    "valid={$reg['valid']} exc=" . count($reg['exceptions']));
$ok('NBB net truncated 370.9518 -> 370.951', abs($byId[9101]['net'] - 370.951) < 1e-9, '(' . $byId[9101]['net'] . ')');
$ok('BBK net truncated 250.2509 -> 250.250', abs($byId[9103]['net'] - 250.250) < 1e-9, '(' . $byId[9103]['net'] . ')');
$ok('bad IBAN flagged (len + bank)', !$byId[9104]['valid']
    && !$byId[9104]['qa']['iban_len_ok'] && !$byId[9104]['qa']['bank_known']);
$ok('no IBAN flagged', !$byId[9105]['valid'] && !$byId[9105]['qa']['iban_present']);
$ok('mismatch: valid but reconcile=false', $byId[9106]['valid'] && !$byId[9106]['qa']['reconciles']);

$files = $bf->transferFiles($run);
$ok('groups NBB + KHCB + BBK present', isset($files['NBB'], $files['KHCB'], $files['BBK']),
    'groups=' . implode(',', array_keys($files)));
$ok('NBB file has 2 rows (9101 + 9106)', ($files['NBB']['count'] ?? 0) === 2);
$ok('BBK file CSV carries truncated net', str_contains($files['BBK']['content'] ?? '', '250.250'));
$ok('KHCB file 1 row, total 500', ($files['KHCB']['count'] ?? 0) === 1 && abs(($files['KHCB']['total'] ?? 0) - 500) < 1e-9);

echo "\n" . ($fail ? "BANK-FILE CHECK: $fail FAILED\n" : "BANK-FILE CHECK: ALL PASSED\n");
exit($fail ? 1 : 0);
