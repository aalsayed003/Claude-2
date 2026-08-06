<?php
/**
 * Build a combined sqlite test DB for the merged HRMS app: roster tables + seed
 * (reused from the duty-roster harness) + translated payroll Pay_* tables +
 * config-driven legacy payroll masters (CurrentDetails/CurrentMonth) + a seeded
 * salary structure. Everything one app, one login (dr_app_users).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$DB = getenv('HRMS_TEST_DB') ?: '/tmp/claude-0/-home-user-Claude-2/90485413-cbc0-5afd-b5d1-c26f8d1788b8/scratchpad/hrms/test.sqlite';
@mkdir(dirname($DB), 0777, true);
@unlink($DB);

// 1) Roster tables + seed (reuse the duty-roster harness build_db.php verbatim).
putenv("DUTYROSTER_TEST_DB=$DB");
passthru('DUTYROSTER_TEST_DB=' . escapeshellarg($DB) . ' php ' .
    escapeshellarg('/tmp/claude-0/-home-user-Claude-2/90485413-cbc0-5afd-b5d1-c26f8d1788b8/scratchpad/testenv/build_db.php') . ' >/dev/null 2>&1');

$pdo = new PDO('sqlite:' . $DB);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* -------- 2) Translate + create payroll Pay_* tables (T-SQL -> sqlite) -------- */
$sql = file_get_contents('/home/user/Claude-2/hrms/database/payroll/schema.sqlserver.sql');
$sql = preg_replace('/--[^\n]*/', '', $sql);                       // strip ALL line comments first (they contain ';')
foreach (explode(';', $sql) as $stmt) {
    if (!preg_match('/create\s+table/i', $stmt)) continue;         // skip indexes/other
    // strip the "IF OBJECT_ID(...) IS NULL" guard
    $stmt = preg_replace('/IF\s+OBJECT_ID\([^)]*\)\s+IS\s+NULL/i', '', $stmt);
    // type translations
    $stmt = preg_replace('/INT\s+IDENTITY\(\d+,\s*\d+\)\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $stmt);
    $stmt = preg_replace('/IDENTITY\(\d+,\s*\d+\)/i', '', $stmt);
    $stmt = preg_replace('/N\'/i', "'", $stmt);
    $stmt = preg_replace('/\b(NVARCHAR|VARCHAR|NCHAR|CHAR)\s*\(\s*(MAX|\d+)\s*\)/i', 'TEXT', $stmt);
    $stmt = preg_replace('/\b(SMALLDATETIME|DATETIME2|DATETIME|DATE|TIME)\b/i', 'TEXT', $stmt);
    $stmt = preg_replace('/\b(TINYINT|SMALLINT|BIGINT|BIT|INT)\b/i', 'INTEGER', $stmt);
    $stmt = preg_replace('/\b(NUMERIC|DECIMAL)\s*\(\s*\d+\s*,\s*\d+\s*\)/i', 'NUMERIC', $stmt);
    $stmt = preg_replace('/\b(MONEY)\b/i', 'NUMERIC', $stmt);
    $stmt = preg_replace('/\b(FLOAT|REAL)\b/i', 'REAL', $stmt);
    $stmt = preg_replace('/DEFAULT\s+GETDATE\(\)/i', "DEFAULT CURRENT_TIMESTAMP", $stmt);
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    try { $pdo->exec($stmt); }
    catch (\Throwable $e) { echo "SKIP Pay table: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 60) . " -> " . $e->getMessage() . "\n"; }
}

/* -------- 3) Legacy payroll masters (columns from config payroll.components) -- */
$cfg = require '/home/user/Claude-2/hrms/config/config.example.php';
$structCols = $regCols = [];
foreach ($cfg['payroll']['components'] as $v) {
    if (is_array($v)) {
        if (!empty($v['structure'])) $structCols[$v['structure']] = 1;
        if (!empty($v['register']))  $regCols[$v['register']] = 1;
    }
}
$colDefs = fn($cols) => implode(', ', array_map(fn($c) => "\"$c\" NUMERIC", array_keys($cols)));
$pdo->exec("CREATE TABLE CurrentDetails (Empid INT, CurrentMonth TEXT, Deleted INT DEFAULT 0, "
    . $colDefs($structCols) . ")");
// Register: union of register + structure component columns (the engine may
// write either name; sqlite matches column names case-insensitively).
$pdo->exec("CREATE TABLE CurrentMonth (Empid INT, CurrentMonth TEXT, Deleted INT DEFAULT 0, "
    . "EmployeeCode TEXT, EmployeeName TEXT, DeptID INT, Departmentid INT, Designationid INT, Categoryid INT, "
    . "Operatorid INT, StateID INT, Netpay NUMERIC, NetPayment NUMERIC, GrossPay NUMERIC, TotalEarnings NUMERIC, TotalDeduction NUMERIC, "
    . "NoofDaysattended NUMERIC, LEAVE NUMERIC, NHoliDays NUMERIC, payabledays NUMERIC, absentdays NUMERIC, "
    . "unpaidleavedays NUMERIC, bankid INT, Accno TEXT, Mode INT, "
    . $colDefs($regCols + $structCols) . ")");
$pdo->exec("CREATE TABLE MonthlyAllowances (Empid INT, CurrentMonth TEXT, Deleted INT DEFAULT 0, Amount NUMERIC, Remarks TEXT)");

/* -------- 4) Payroll seed (GOSI, banks) + one salary structure --------------- */
$seed = file_get_contents('/home/user/Claude-2/hrms/database/payroll/seed.sqlserver.sql');
$seed = preg_replace('/--[^\n]*/', '', $seed);                     // strip ALL line comments first
foreach (explode(';', $seed) as $stmt) {
    if (!preg_match('/insert\s+into/i', $stmt)) continue;
    $stmt = preg_replace('/IF\s+NOT\s+EXISTS\s*\(.*?\)/is', '', $stmt);   // drop guard
    $stmt = preg_replace('/N\'/', "'", $stmt);
    $stmt = trim($stmt);
    if ($stmt === '' || stripos($stmt, 'Pay_Users') !== false) continue;   // login uses dr_app_users
    try { $pdo->exec($stmt); }
    catch (\Throwable $e) { echo "SKIP seed: " . $e->getMessage() . "\n"; }
}
// Salary structure for Nurse Mona (202): Basic 500, HRA 100, Trsp 50 (payroll month July 2026).
$pdo->exec("INSERT INTO CurrentDetails (Empid, CurrentMonth, Deleted, BasicSalary, HRA, Trsp)
            VALUES (202, '2026-07-01', 0, 500, 100, 50)");
// bank row for WPS
try { $pdo->exec("INSERT INTO Pay_Bank (Code, Name, SwiftCode) VALUES ('TEST','Test Bank','TESTBHBM')"); } catch (\Throwable $e) {}

/* -------- report -------- */
$n = fn($t) => (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
echo "HRMS test DB built: $DB\n";
echo "  Pay_* tables: " . (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'Pay_%'")->fetchColumn() . "\n";
echo "  CurrentDetails rows: " . $n('CurrentDetails') . " ; Pay_GosiRate: " . $n('Pay_GosiRate') . " ; Pay_Bank: " . $n('Pay_Bank') . "\n";
echo "  dr_app_users: " . $n('dr_app_users') . " ; Employee: " . $n('Employee') . " ; checkinout: " . $n('checkinout') . "\n";
