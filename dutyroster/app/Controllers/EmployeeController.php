<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class EmployeeController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('dept_head');
        $q = $this->input('q', '');
        if (legacy_mode()) {
            $emps = (new \App\Repositories\EmployeeRepository($this->db))->search($q);
        } else {
            $params = [];
            $where = '1=1';
            if ($q !== '') {
                $where .= " AND (e.full_name LIKE :q OR e.emp_id LIKE :q OR e.pin LIKE :q)";
                $params[':q'] = "%{$q}%";
            }
            $emps = $this->db->all(
                $this->db->limit(
                    "SELECT e.*, d.name AS dept_name, s.name AS section_name
                       FROM employees e
                       LEFT JOIN departments d ON d.id = e.department_id
                       LEFT JOIN sections s ON s.id = e.section_id
                      WHERE {$where}
                      ORDER BY e.full_name", 500),
                $params
            );
        }
        $this->view('employees/index', [
            'title' => 'Employees',
            'emps'  => $emps,
            'q'     => $q,
        ]);
    }

    public function create(): void
    {
        Auth::requireRole('admin');
        [$depts, $sections] = $this->pickerLists();
        $this->view('employees/form', [
            'title' => 'New Employee',
            'emp'   => null,
            'depts' => $depts,
            'sections' => $sections,
        ]);
    }

    public function edit(): void
    {
        Auth::requireRole('admin');
        $id = (int) $this->input('id');
        $emp = legacy_mode()
            ? (new \App\Repositories\EmployeeRepository($this->db))->find($id)
            : $this->db->one("SELECT * FROM employees WHERE id = :id", [':id' => $id]);
        if (!$emp) {
            $this->flash('error', 'Employee not found.');
            $this->redirect('employees');
        }
        [$depts, $sections] = $this->pickerLists();
        $this->view('employees/form', [
            'title' => 'Edit Employee',
            'emp'   => $emp,
            'depts' => $depts,
            'sections' => $sections,
        ]);
    }

    public function save(): void
    {
        Auth::requireRole('admin');
        $this->verifyCsrf();
        $id = (int) ($this->input('id') ?: 0);
        $data = [
            'emp_id'       => $this->input('emp_id', ''),
            'pin'          => $this->input('pin', ''),
            'full_name'    => $this->input('full_name', ''),
            'department_id'=> $this->input('department_id') ?: null,
            'section_id'   => $this->input('section_id') ?: null,
            'designation'  => $this->input('designation') ?: null,
            'is_dept_head' => $this->input('is_dept_head') ? 1 : 0,
            'active'       => $this->input('active') !== null ? 1 : 1,
        ];
        if ($data['emp_id'] === '' || $data['pin'] === '' || $data['full_name'] === '') {
            $this->flash('error', 'Employee ID, PIN and name are required.');
            $this->redirect('employees/new');
        }
        if (legacy_mode()) {
            $this->saveLegacyEmployee($id, $data);
            $this->flash('success', $id ? 'Employee updated.' : 'Employee added.');
            $this->redirect('employees');
        }
        if ($id) {
            $this->db->update('employees', $data, 'id = :id', [':id' => $id]);
            $this->flash('success', 'Employee updated.');
        } else {
            $this->db->insert('employees', $data);
            $this->flash('success', 'Employee added.');
        }
        $this->redirect('employees');
    }

    /** Department + section dropdown data (legacy has no section master). */
    private function pickerLists(): array
    {
        if (legacy_mode()) {
            return [(new \App\Repositories\DepartmentRepository($this->db))->all(), []];
        }
        return [
            $this->db->all("SELECT * FROM departments ORDER BY name"),
            $this->db->all("SELECT * FROM sections ORDER BY name"),
        ];
    }

    /**
     * Write an employee to the legacy `Employee` master. The master has several
     * NOT NULL columns (EmpCode, FirstName, Middlename, DesignationId,
     * StartDateTime, Deleted, DepartmentId), so an insert supplies all of them;
     * an update only touches the fields the form owns (leaving the rest intact).
     * The free-text designation is resolved to a Designation.ID when it matches
     * an existing one. ID is supplied identity-safe (TestASSH dropped IDENTITY).
     */
    private function saveLegacyEmployee(int $id, array $data): void
    {
        $emp = lt('employee');
        $desigId = $this->resolveDesignationId($data['designation']);
        $row = [
            'EmployeeId'   => $data['emp_id'],
            'Name'         => $data['full_name'],
            'DepartmentId' => $data['department_id'] !== null ? (int) $data['department_id'] : 0,
            'IsHead'       => $data['is_dept_head'],
        ];
        if ($desigId !== null) {
            $row['DesignationId'] = $desigId;
        }
        if ($id) {
            $this->db->update($emp, $row, 'ID = :id', [':id' => $id]);
        } else {
            $parts = preg_split('/\s+/', trim($data['full_name']));
            $row['EmpCode']       = $data['emp_id'];
            $row['FirstName']     = $parts[0] ?? $data['full_name'];
            $row['Middlename']    = '';
            $row['DesignationId'] = $desigId ?? 0;
            $row['StartDateTime'] = date('Y-m-d H:i:s');
            $row['Deleted']       = 0;
            $this->db->insertLegacy($emp, $row, 'ID');
        }
    }

    /** Resolve a free-text designation to an existing Designation.ID, or null. */
    private function resolveDesignationId(?string $designation): ?int
    {
        $designation = trim((string) $designation);
        if ($designation === '') {
            return null;
        }
        if (ctype_digit($designation)) {
            return (int) $designation;
        }
        $des = lt('designation');
        $hit = $this->db->value(
            "SELECT ID FROM {$des} WHERE Deleted = 0 AND (Name = :n OR Code = :n)",
            [':n' => $designation]
        );
        return $hit !== null ? (int) $hit : null;
    }
}
