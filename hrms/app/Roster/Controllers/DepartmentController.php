<?php
namespace App\Roster\Controllers;

use App\Core\Controller;
use App\Core\Auth;

class DepartmentController extends Controller
{
    public function index(): void
    {
        Auth::requireRole('admin');
        if (legacy_mode()) {
            $depts = (new \App\Roster\Repositories\DepartmentRepository($this->db))->all();
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
        if (legacy_mode()) {
            $this->saveLegacyDepartment($id, $name, $this->input('code') ?: null);
            $this->flash('success', 'Department saved.');
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

    /**
     * Write a department to the legacy `Department` master. DeptCode, Name,
     * StartDateTime and Deleted are NOT NULL; Id is supplied identity-safe.
     * A blank code falls back to an uppercased slug of the name.
     */
    private function saveLegacyDepartment(int $id, string $name, ?string $code): void
    {
        $dep = lt('department');
        $code = $code ?: strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name), 0, 10));
        $row = ['Name' => $name, 'DeptCode' => $code];
        if ($id) {
            $this->db->update($dep, $row, 'Id = :id', [':id' => $id]);
        } else {
            $row['StartDateTime'] = date('Y-m-d H:i:s');
            $row['Deleted'] = 0;
            $this->db->insertLegacy($dep, $row, 'Id');
        }
    }

    public function saveSection(): void
    {
        Auth::requireRole('admin');
        $this->verifyCsrf();
        // The legacy ASSH schema has no Section master, so sections are not
        // stored in legacy mode. Report that clearly instead of hitting a
        // non-existent `sections` table.
        if (legacy_mode()) {
            $this->flash('error', 'Sections are not used in the legacy database — departments only.');
            $this->redirect('departments');
        }
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
