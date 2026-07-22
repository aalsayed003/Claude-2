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
        $this->view('employees/form', [
            'title' => 'New Employee',
            'emp'   => null,
            'depts' => $this->db->all("SELECT * FROM departments ORDER BY name"),
            'sections' => $this->db->all("SELECT * FROM sections ORDER BY name"),
        ]);
    }

    public function edit(): void
    {
        Auth::requireRole('admin');
        $id = (int) $this->input('id');
        $emp = $this->db->one("SELECT * FROM employees WHERE id = :id", [':id' => $id]);
        if (!$emp) {
            $this->flash('error', 'Employee not found.');
            $this->redirect('employees');
        }
        $this->view('employees/form', [
            'title' => 'Edit Employee',
            'emp'   => $emp,
            'depts' => $this->db->all("SELECT * FROM departments ORDER BY name"),
            'sections' => $this->db->all("SELECT * FROM sections ORDER BY name"),
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
        if ($id) {
            $this->db->update('employees', $data, 'id = :id', [':id' => $id]);
            $this->flash('success', 'Employee updated.');
        } else {
            $this->db->insert('employees', $data);
            $this->flash('success', 'Employee added.');
        }
        $this->redirect('employees');
    }
}
