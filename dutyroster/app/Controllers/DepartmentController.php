<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class DepartmentController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('admin');
        if (legacy_mode()) {
            $depts = (new \App\Repositories\DepartmentRepository($this->db))->all();
            $sections = [];   // legacy section master wiring: next iteration
        } else {
            $depts = $this->db->all("SELECT * FROM departments ORDER BY name");
            $sections = $this->db->all(
                "SELECT s.*, d.name AS dept_name FROM sections s
                   JOIN departments d ON d.id = s.department_id ORDER BY d.name, s.name"
            );
        }
        $this->view('departments/index', [
            'title'    => 'Departments & Sections',
            'depts'    => $depts,
            'sections' => $sections,
        ]);
    }

    public function save(): void
    {
        Auth::requireRole('admin');
        $this->verifyCsrf();
        $id   = (int) ($this->input('id') ?: 0);
        $name = $this->input('name', '');
        if ($name === '') {
            $this->flash('error', 'Department name is required.');
            $this->redirect('departments');
        }
        $data = ['name' => $name, 'code' => $this->input('code') ?: null];
        if ($id) {
            $this->db->update('departments', $data, 'id = :id', [':id' => $id]);
        } else {
            $this->db->insert('departments', $data);
        }
        $this->flash('success', 'Department saved.');
        $this->redirect('departments');
    }

    public function saveSection(): void
    {
        Auth::requireRole('admin');
        $this->verifyCsrf();
        $deptId = (int) $this->input('department_id');
        $name   = $this->input('name', '');
        if (!$deptId || $name === '') {
            $this->flash('error', 'Section name and department are required.');
            $this->redirect('departments');
        }
        $this->db->insert('sections', ['department_id' => $deptId, 'name' => $name]);
        $this->flash('success', 'Section saved.');
        $this->redirect('departments');
    }
}
