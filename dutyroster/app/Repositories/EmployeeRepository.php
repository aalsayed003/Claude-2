<?php
namespace App\Repositories;

use App\Core\Database;

/**
 * Reads employees from the legacy `Employee` master and shapes each row into
 * the structure the app's views expect (emp_id, pin, full_name, dept_name …).
 *
 * Legacy columns used: ID (identity PK), EmpCode (the code shown in the UI),
 * Name (computed full name), DepartmentId (-> Department.Id), DesignationId
 * (-> Designation.ID), IsHead, Deleted.
 */
class EmployeeRepository
{
    public function __construct(private Database $db) {}

    public function search(string $q = '', int $limit = 500): array
    {
        $emp = lt('employee');
        $dep = lt('department');
        $des = lt('designation');

        $where  = 'e.Deleted = 0';
        $params = [];
        if ($q !== '') {
            $where .= ' AND (e.Name LIKE :q OR e.EmployeeId LIKE :q)';
            $params[':q'] = "%{$q}%";
        }

        // EmployeeId (not EmpCode) is the biometric/UI code that Atten_/attendance
        // rows join on (C.EmployeeID = D.EmpID in the legacy procs).
        $sql = "SELECT e.ID AS id, e.EmployeeId AS emp_id, e.Name AS full_name,
                       e.DepartmentId AS department_id, d.Name AS dept_name,
                       des.Name AS designation, e.IsHead AS is_head
                  FROM {$emp} e
                  LEFT JOIN {$dep} d   ON d.Id  = e.DepartmentId
                  LEFT JOIN {$des} des ON des.ID = e.DesignationId
                 WHERE {$where}
                 ORDER BY e.Name";

        return array_map([$this, 'shape'], $this->db->all($this->db->limit($sql, $limit), $params));
    }

    public function find(int $id): ?array
    {
        $emp = lt('employee'); $dep = lt('department'); $des = lt('designation');
        $row = $this->db->one(
            "SELECT e.ID AS id, e.EmployeeId AS emp_id, e.Name AS full_name,
                    e.DepartmentId AS department_id, d.Name AS dept_name,
                    des.Name AS designation, e.IsHead AS is_head
               FROM {$emp} e
               LEFT JOIN {$dep} d   ON d.Id  = e.DepartmentId
               LEFT JOIN {$des} des ON des.ID = e.DesignationId
              WHERE e.ID = :id",
            [':id' => $id]
        );
        return $row ? $this->shape($row) : null;
    }

    /** Lookup by the biometric code (EmpCode). Used to link punches. */
    public function findByCode(string $empCode): ?array
    {
        $emp = lt('employee');
        $row = $this->db->one(
            "SELECT ID AS id, EmployeeId AS emp_id, Name AS full_name,
                    DepartmentId AS department_id, IsHead AS is_head
               FROM {$emp} WHERE EmployeeId = :c AND Deleted = 0",
            [':c' => $empCode]
        );
        return $row ? $this->shape($row) : null;
    }

    /** Simple id/name list for dropdowns. */
    public function options(): array
    {
        return $this->search('', 2000);
    }

    /** Map a legacy row to the app's canonical employee shape. */
    private function shape(array $r): array
    {
        $code = (string) ($r['emp_id'] ?? '');
        return [
            'id'            => (int) $r['id'],
            'emp_id'        => $code,
            'pin'           => pin_from_code($code),
            'full_name'     => trim((string) ($r['full_name'] ?? '')),
            'department_id' => $r['department_id'] !== null ? (int) $r['department_id'] : null,
            'dept_name'     => $r['dept_name'] ?? null,
            'section_name'  => null,                 // legacy section wiring: next iteration
            'designation'   => $r['designation'] ?? null,
            'is_dept_head'  => (int) ($r['is_head'] ?? 0) ? 1 : 0,
        ];
    }
}
