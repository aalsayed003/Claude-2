<?php
/**
 * load_punches.php  —  the PHP equivalent of sp_HRMS_LoadPunching.
 *
 * Pulls NEW rows from the BioTime `checkinout` source table into the app's
 * `punches` table, maps each PIN to an employee, then recomputes attendance
 * for the affected days. Run every 5 minutes from cron / Task Scheduler:
 *
 *   Linux  : *\/5 * * * * php /path/dutyroster/ingest/load_punches.php >> /var/log/dutyroster.log 2>&1
 *   Windows: schtasks /create /sc minute /mo 5 /tn DutyRosterPunch ^
 *              /tr "php C:\dutyroster\ingest\load_punches.php"
 *
 * Idempotent: it tracks the highest imported source id and only reads beyond it.
 */

use App\Core\Config;
use App\Core\Database;
use App\Services\AttendanceEngine;

require dirname(__DIR__) . '/app/bootstrap.php';

$log = fn(string $m) => fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] {$m}\n");

$app = Database::app();

$src = Config::get('punch_source');
if (empty($src['enabled'])) {
    $log('punch_source disabled in config — nothing to do. Enable it once BioTime access is set up.');
    exit(0);
}

// Highest source id already imported.
$lastId = (int) ($app->value("SELECT COALESCE(MAX(source_id), 0) FROM punches") ?? 0);
$log("Last imported source_id = {$lastId}");

$source = new Database($src);
$rows = $source->all($src['query'], [':last_id' => $lastId]);
$log('Fetched ' . count($rows) . ' new punch(es) from source.');

if (!$rows) {
    exit(0);
}

// Cache PIN -> employee_id.
$pinMap = [];
foreach ($app->all("SELECT id, pin FROM employees") as $e) {
    $pinMap[ltrim($e['pin'], '0')] = (int) $e['id'];
    $pinMap[$e['pin']] = (int) $e['id'];
}

$affected = [];   // "employeeId|date" set for recompute
$imported = 0;
$unknown  = 0;

$app->begin();
try {
    foreach ($rows as $r) {
        $pin  = (string) $r['pin'];
        $empId = $pinMap[$pin] ?? $pinMap[ltrim($pin, '0')] ?? null;
        if ($empId === null) {
            $unknown++;
        }
        $punchTime = normalize_time($r['punch_time']);
        $app->insert('punches', [
            'source_id'   => $r['source_id'] ?? null,
            'pin'         => $pin,
            'employee_id' => $empId,
            'punch_time'  => $punchTime,
            'check_type'  => $r['check_type'] ?? null,
            'device_name' => $r['device_name'] ?? null,
            'device_sn'   => $r['device_sn'] ?? null,
            'area_name'   => $r['area_name'] ?? null,
        ]);
        $imported++;
        if ($empId !== null) {
            $affected[$empId . '|' . substr($punchTime, 0, 10)] = [$empId, substr($punchTime, 0, 10)];
        }
    }
    $app->commit();
} catch (\Throwable $ex) {
    $app->rollback();
    $log('ERROR: ' . $ex->getMessage());
    exit(1);
}

$log("Imported {$imported} punch(es); {$unknown} had unknown PINs.");

// Recompute attendance for every affected employee/day.
$engine = new AttendanceEngine($app);
foreach ($affected as [$empId, $date]) {
    $engine->rebuildEmployee($empId, $date, $date);
}
$log('Recomputed attendance for ' . count($affected) . ' employee-day(s). Done.');

/**
 * Accepts a native datetime string or an Excel serial number (as seen when
 * the feed is exported through Excel) and returns 'Y-m-d H:i:s'.
 */
function normalize_time($v): string
{
    if (is_numeric($v)) {
        // Excel serial date: days since 1899-12-30.
        $seconds = ((float) $v - 25569) * 86400;
        return gmdate('Y-m-d H:i:s', (int) round($seconds));
    }
    return date('Y-m-d H:i:s', strtotime((string) $v));
}
