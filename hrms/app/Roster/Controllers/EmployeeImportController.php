<?php
namespace App\Roster\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Roster\Services\SpreadsheetReader;

/**
 * Bulk employee import from CSV / XLSX.
 *
 * Recognised columns (header names, case/space/underscore-insensitive):
 *   emp_id*        employee code shown in the UI (e.g. 01732)
 *   pin            9-digit biometric PIN; if blank, derived from emp_id
 *   full_name*     employee name
 *   department     department name (auto-created if new)
 *   section        section name (auto-created under the department)
 *   designation    job title
 *   is_dept_head   yes/true/1 to flag a department head
 *   active         no/false/0 to mark inactive (default active)
 *
 * Flow: form -> preview (validate, stash temp file) -> commit (upsert).
 */
class EmployeeImportController extends Controller
{
    private const FIELDS = ['emp_id','pin','full_name','department','section','designation','is_dept_head','active'];

    public function form(): void
    {
        Auth::requireRole('admin');
        $this->view('employees/import', ['title' => 'Import Employees', 'preview' => null]);
    }

    public function preview(): void
    {
        Auth::requireRole('admin');
        $this->verifyCsrf();

        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash('error', 'Please choose a CSV or XLSX file to upload.');
            $this->redirect('employees/import');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'xlsx'], true)) {
            $this->flash('error', 'Unsupported file type. Use .csv or .xlsx.');
            $this->redirect('employees/import');
        }

        // Stash the upload in a temp file so commit can re-read it.
        $token = bin2hex(random_bytes(8)) . '.' . $ext;
        $tmp   = sys_get_temp_dir() . '/dutyroster_imp_' . $token;
        move_uploaded_file($file['tmp_name'], $tmp);

        try {
            [$headers, $rows] = SpreadsheetReader::read($tmp, $ext);
        } catch (\Throwable $e) {
            @unlink($tmp);
            $this->flash('error', 'Could not read the file: ' . $e->getMessage());
            $this->redirect('employees/import');
        }

        $parsed = $this->validateRows($rows);

        $this->view('employees/import', [
            'title'   => 'Import Employees — Preview',
            'preview' => [
                'token'   => $token,
                'headers' => $headers,
                'rows'    => $parsed['rows'],
                'valid'   => $parsed['valid'],
                'invalid' => $parsed['invalid'],
                'total'   => count($parsed['rows']),
            ],
        ]);
    }

    public function commit(): void
    {
        Auth::requireRole('admin');
        $this->verifyCsrf();

        $token = basename((string) $this->input('token'));   // prevent path traversal
        $ext   = strtolower(pathinfo($token, PATHINFO_EXTENSION));
        $tmp   = sys_get_temp_dir() . '/dutyroster_imp_' . $token;
        if (!$token || !is_file($tmp)) {
            $this->flash('error', 'Upload session expired. Please re-upload the file.');
            $this->redirect('employees/import');
        }

        [$headers, $rows] = SpreadsheetReader::read($tmp, $ext);
        $parsed = $this->validateRows($rows);

        if (legacy_mode()) {
            $this->commitLegacy($parsed, $tmp);
            return;
        }

        $deptCache = $this->nameIdMap('departments');
        $secCache  = [];   // "deptId|name" => sectionId
        $inserted = $updated = 0;

        $this->db->begin();
        try {
            foreach ($parsed['rows'] as $r) {
                if (!$r['_valid']) continue;

                $deptId = null;
                if ($r['department'] !== '') {
                    $key = mb_strtolower($r['department']);
                    if (!isset($deptCache[$key])) {
                        $deptCache[$key] = $this->db->insert('departments', ['name' => $r['department']]);
                    }
                    $deptId = $deptCache[$key];
                }

                $sectionId = null;
                if ($r['section'] !== '' && $deptId) {
                    $sk = $deptId . '|' . mb_strtolower($r['section']);
                    if (!isset($secCache[$sk])) {
                        $existing = $this->db->one(
                            "SELECT id FROM sections WHERE department_id=:d AND name=:n",
                            [':d' => $deptId, ':n' => $r['section']]
                        );
                        $secCache[$sk] = $existing['id']
                            ?? $this->db->insert('sections', ['department_id' => $deptId, 'name' => $r['section']]);
                    }
                    $sectionId = $secCache[$sk];
                }

                $data = [
                    'emp_id'        => $r['emp_id'],
                    'pin'           => $r['pin'],
                    'full_name'     => $r['full_name'],
                    'department_id' => $deptId,
                    'section_id'    => $sectionId,
                    'designation'   => $r['designation'] ?: null,
                    'is_dept_head'  => $r['is_dept_head'],
                    'active'        => $r['active'],
                ];

                $existing = $this->db->one("SELECT id FROM employees WHERE emp_id=:e", [':e' => $r['emp_id']]);
                if ($existing) {
                    $this->db->update('employees', $data, 'id=:id', [':id' => $existing['id']]);
                    $updated++;
                } else {
                    $this->db->insert('employees', $data);
                    $inserted++;
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            @unlink($tmp);
            $this->flash('error', 'Import failed: ' . $e->getMessage());
            $this->redirect('employees/import');
        }

        @unlink($tmp);
        $skipped = $parsed['invalid'];
        $this->flash('success', "Import complete — {$inserted} added, {$updated} updated"
            . ($skipped ? ", {$skipped} skipped (invalid)" : '') . '.');
        $this->redirect('employees');
    }

    /**
     * Commit an import against the legacy masters: upsert into `Employee`
     * (keyed by EmployeeId), auto-creating a legacy `Department` by name when
     * needed and resolving the designation to a Designation.ID. Legacy has no
     * section master, so the section column is ignored. `active` maps to the
     * Deleted flag (active=1 -> Deleted=0).
     */
    private function commitLegacy(array $parsed, string $tmp): void
    {
        $empT = lt('employee');
        $depT = lt('department');
        $desT = lt('designation');

        // name (lowercased) -> legacy Department.Id
        $deptCache = [];
        foreach ($this->db->all("SELECT Id AS id, Name AS name FROM {$depT} WHERE Deleted = 0") as $row) {
            $deptCache[mb_strtolower(trim((string) $row['name']))] = (int) $row['id'];
        }
        // name/code (lowercased) -> legacy Designation.ID
        $desCache = [];
        foreach ($this->db->all("SELECT ID AS id, Name AS name, Code AS code FROM {$desT} WHERE Deleted = 0") as $row) {
            if (($row['name'] ?? '') !== '') $desCache[mb_strtolower(trim((string) $row['name']))] = (int) $row['id'];
            if (($row['code'] ?? '') !== '') $desCache[mb_strtolower(trim((string) $row['code']))] = (int) $row['id'];
        }

        $inserted = $updated = 0;
        $this->db->begin();
        try {
            foreach ($parsed['rows'] as $r) {
                if (!$r['_valid']) continue;

                $deptId = 0;
                if ($r['department'] !== '') {
                    $key = mb_strtolower(trim($r['department']));
                    if (!isset($deptCache[$key])) {
                        $deptCache[$key] = $this->db->insertLegacy($depT, [
                            'Name'          => $r['department'],
                            'DeptCode'      => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $r['department']), 0, 10)),
                            'StartDateTime' => date('Y-m-d H:i:s'),
                            'Deleted'       => 0,
                        ], 'Id');
                    }
                    $deptId = $deptCache[$key];
                }

                $desigId = 0;
                if ($r['designation'] !== '') {
                    $desigId = $desCache[mb_strtolower(trim($r['designation']))] ?? 0;
                }

                $existing = $this->db->one("SELECT ID FROM {$empT} WHERE EmployeeId = :e", [':e' => $r['emp_id']]);
                if ($existing) {
                    $this->db->update($empT, [
                        'Name'         => $r['full_name'],
                        'DepartmentId' => $deptId,
                        'IsHead'       => $r['is_dept_head'],
                        'Deleted'      => $r['active'] ? 0 : 1,
                    ], 'ID = :id', [':id' => $existing['ID']]);
                    $updated++;
                } else {
                    $parts = preg_split('/\s+/', trim($r['full_name']));
                    $this->db->insertLegacy($empT, [
                        'EmployeeId'    => $r['emp_id'],
                        'EmpCode'       => $r['emp_id'],
                        'Name'          => $r['full_name'],
                        'FirstName'     => $parts[0] ?? $r['full_name'],
                        'Middlename'    => '',
                        'DepartmentId'  => $deptId,
                        'DesignationId' => $desigId,
                        'IsHead'        => $r['is_dept_head'],
                        'StartDateTime' => date('Y-m-d H:i:s'),
                        'Deleted'       => $r['active'] ? 0 : 1,
                    ], 'ID');
                    $inserted++;
                }
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            @unlink($tmp);
            $this->flash('error', 'Import failed: ' . $e->getMessage());
            $this->redirect('employees/import');
        }

        @unlink($tmp);
        $skipped = $parsed['invalid'];
        $this->flash('success', "Import complete — {$inserted} added, {$updated} updated"
            . ($skipped ? ", {$skipped} skipped (invalid)" : '') . '.');
        $this->redirect('employees');
    }

    /** Download a ready-to-fill CSV template. */
    public function template(): void
    {
        Auth::requireRole('admin');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="employees_template.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
        // Explicit escape ('' = none): PHP 8.4 deprecates the default escape arg.
        fputcsv($out, ['emp_id','pin','full_name','department','section','designation','is_dept_head','active'], ',', '"', '');
        fputcsv($out, ['01732','000001732','Hawra Abdulhusain Ahmed Alasfoor','Information And Communication Technology','General','IT Programmer','no','yes'], ',', '"', '');
        fputcsv($out, ['01013','000001013','Joby Kaitharath George','Information And Communication Technology','General','','no','yes'], ',', '"', '');
        fclose($out);
        exit;
    }

    // ---------------------------------------------------------------

    private function validateRows(array $rows): array
    {
        $out = [];
        $valid = $invalid = 0;
        $seenEmp = [];
        foreach ($rows as $raw) {
            $r = [];
            foreach (self::FIELDS as $f) {
                $r[$f] = $raw[$f] ?? '';
            }
            // Derive/normalise PIN: zero-pad to 9 digits like the device sync does.
            $r['emp_id'] = trim($r['emp_id']);
            $digits = preg_replace('/\D/', '', $r['pin'] ?: $r['emp_id']);
            $r['pin'] = $digits !== '' ? str_pad($digits, 9, '0', STR_PAD_LEFT) : '';
            $r['is_dept_head'] = $this->truthy($r['is_dept_head']) ? 1 : 0;
            $r['active'] = ($raw['active'] ?? '') === '' ? 1 : ($this->truthy($r['active']) ? 1 : 0);

            $errors = [];
            if ($r['emp_id'] === '')    $errors[] = 'emp_id missing';
            if ($r['full_name'] === '') $errors[] = 'name missing';
            if ($r['pin'] === '')       $errors[] = 'pin missing';
            if ($r['emp_id'] !== '' && isset($seenEmp[$r['emp_id']])) $errors[] = 'duplicate emp_id in file';
            if ($r['emp_id'] !== '') $seenEmp[$r['emp_id']] = true;

            $r['_valid']  = empty($errors);
            $r['_errors'] = implode('; ', $errors);
            $r['_valid'] ? $valid++ : $invalid++;
            $out[] = $r;
        }
        return ['rows' => $out, 'valid' => $valid, 'invalid' => $invalid];
    }

    private function truthy(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['1','y','yes','true','x','head'], true);
    }

    private function nameIdMap(string $table): array
    {
        $map = [];
        foreach ($this->db->all("SELECT id, name FROM {$table}") as $row) {
            $map[mb_strtolower($row['name'])] = (int) $row['id'];
        }
        return $map;
    }
}
