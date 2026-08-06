<?php
/**
 * Targeted end-to-end test for the leave/balance/attachment/dashboard features:
 *   1. Nurse sees balances and submits Annual leave -> days reserved as Pending
 *   2. Over-requesting is rejected (balance guard)
 *   3. Nurse downloads her own sick-note attachment (image streamed)
 *   4. HR sees the sick note + OCR text and the salary-certificate queue
 *   5. HR approves the Annual request -> Pending moves to Used
 *   6. Admin dashboard shows the pending tiles (schedule change / correction /
 *      salary certificate / leave)
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8092';
$DB   = getenv('HRMS_TEST_DB');
$pdo  = new PDO('sqlite:' . $DB);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$PASS = 0; $FAIL = 0;
function ok(string $label, bool $cond, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; printf("  ok    %-52s %s\n", $label, $extra); }
    else       { $FAIL++; printf("  FAIL  %-52s %s\n", $label, $extra); }
}

function req(string $jar, string $m, string $url, ?array $post = null, bool $follow = true): array {
    global $BASE;
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $BASE . '/' . ltrim($url, '/'), CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => 1]);
    if ($m === 'POST') { curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post ?: [])); }
    $r = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$c, substr($r, $hs), substr($r, 0, $hs), $ct];
}
function csrf(string $b): ?string { return preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/', $b, $m) ? $m[1] : null; }
function login(string $jar, string $user): bool {
    [, $b] = req($jar, 'GET', 'login');
    [$c, , $h] = req($jar, 'POST', 'login', ['username' => $user, 'password' => 'admin123', '_csrf' => csrf($b)], false);
    return stripos($h, 'dashboard') !== false;
}
function bal(PDO $pdo, string $type): array {
    $r = $pdo->query("SELECT Entitlement,Used,Pending FROM Pay_LeaveBalance
                       WHERE EmployeeID=202 AND LeaveType='$type' AND LeaveYear=2026")->fetch(PDO::FETCH_ASSOC);
    return $r ?: ['Entitlement' => 0, 'Used' => 0, 'Pending' => 0];
}

$nurse = sys_get_temp_dir() . '/lv_nurse.txt'; @unlink($nurse);
$admin = sys_get_temp_dir() . '/lv_admin.txt'; @unlink($admin);

echo "== 1) Nurse: balances + submit Annual leave ==\n";
ok('login nurse', login($nurse, 'nurse'));
[$c, $b] = req($nurse, 'GET', 'me/leave');
ok('me/leave shows Annual balance', str_contains($b, 'Annual') && str_contains($b, 'days available'));
ok('me/leave shows attachment controls', str_contains($b, 'Take photo') && str_contains($b, 'Choose file'));
$before = bal($pdo, 'Annual');
[$c, $b] = req($nurse, 'POST', 'me/leave/save', ['_csrf' => csrf($b) ?: (function () use ($nurse) { [, $x] = req($nurse, 'GET', 'me/leave'); return csrf($x); })(),
    'leave_type' => 'Annual', 'from_date' => '2026-09-01', 'to_date' => '2026-09-02', 'reason' => 'Family']);
$after = bal($pdo, 'Annual');
ok('Annual pending reserved (+2)', (float) $after['Pending'] === (float) $before['Pending'] + 2,
    "pending {$before['Pending']} -> {$after['Pending']}");

echo "\n== 2) Balance guard: over-request is rejected ==\n";
[, $b] = req($nurse, 'GET', 'me/leave');
$pendBefore = bal($pdo, 'Annual')['Pending'];
[$c, $b] = req($nurse, 'POST', 'me/leave/save', ['_csrf' => csrf($b),
    'leave_type' => 'Annual', 'from_date' => '2026-10-01', 'to_date' => '2027-09-30', 'reason' => 'Too long']);
$pendAfter = bal($pdo, 'Annual')['Pending'];
ok('over-request did not reserve', (float) $pendAfter === (float) $pendBefore, "pending stayed {$pendAfter}");
ok('over-request shows balance error', str_contains($b, 'Not enough') || str_contains($b, 'balance'));

echo "\n== 3) Nurse downloads her own sick-note attachment ==\n";
[$c, $body, $h, $ct] = req($nurse, 'GET', 'me/leave/attachment?id=1', null, false);
ok('attachment streams to owner', $c === 200 && str_contains((string) $ct, 'image/'), "HTTP $c ct=$ct");

echo "\n== 4) HR: salary-cert queue + sick note + OCR ==\n";
ok('login admin (HR)', login($admin, 'admin'));
[$c, $b] = req($admin, 'GET', 'hr/requests?category=' . rawurlencode('Salary certificate'));
ok('salary-certificate queue filtered', str_contains($b, 'Salary certificate for bank'));
[$c, $b] = req($admin, 'GET', 'hr/leave');
ok('HR leave shows attachment link', str_contains($b, 'me/leave/attachment?id=1'));
ok('HR leave shows OCR scanned text', str_contains($b, 'scanned text') && str_contains($b, 'MEDICAL CERTIFICATE'));

echo "\n== 5) HR approves Annual -> Pending becomes Used ==\n";
// find the nurse's pending Annual request id
$rid = (int) $pdo->query("SELECT RequestID FROM Pay_LeaveRequest
                           WHERE EmployeeID=202 AND LeaveType='Annual' AND StateID=1
                           ORDER BY RequestID DESC LIMIT 1")->fetchColumn();
$before = bal($pdo, 'Annual');
[, $b] = req($admin, 'GET', 'hr/leave');
[$c, $b] = req($admin, 'POST', 'hr/leave/decide',
    ['_csrf' => csrf($b), 'request_id' => $rid, 'decision' => 'approve', 'note' => 'OK']);
$after = bal($pdo, 'Annual');
ok('approve moved pending->used', (float) $after['Used'] === (float) $before['Used'] + 2
    && (float) $after['Pending'] === (float) $before['Pending'] - 2,
    "used {$before['Used']}->{$after['Used']}, pending {$before['Pending']}->{$after['Pending']}");

echo "\n== 6) Dashboard pending tiles ==\n";
[$c, $b] = req($admin, 'GET', 'dashboard');
ok('tile: Change Schedule', str_contains($b, 'Change Schedule'));
ok('tile: Attendance Corrections', str_contains($b, 'Attendance Corrections'));
ok('tile: Salary Certificates', str_contains($b, 'Salary Certificates'));
ok('tile: Leave Requests', str_contains($b, 'Leave Requests'));

echo "\n=============================\n";
printf("LEAVE FLOW: PASS %d / %d\n", $PASS, $PASS + $FAIL);
echo $FAIL ? "SOME CHECKS FAILED\n" : "ALL LEAVE-FLOW CHECKS PASSED\n";
exit($FAIL ? 1 : 0);
