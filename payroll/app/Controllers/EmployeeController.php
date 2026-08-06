<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Repositories\EmployeeRepository;

/**
 * Employee master — add and edit staff.
 *
 * Records are held in the shared HR master (`Employee`), so an employee added
 * here is the same record the Duty Roster / HRMS sees. Only the fields payroll
 * needs are captured; the rest keep their defaults. Salary and bank details are
 * set separately on the salary-structure screen.
 */
class EmployeeController extends Controller
{
    private EmployeeRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new EmployeeRepository($this->db);
    }

    public function index(): void
    {
        $this->requireRole('process');
        $q = (string) $this->input('q', '');
        $this->view('employees/index', [
            'title'     => 'Employees',
            'q'         => $q,
            'employees' => $this->repo->search($q, 500),
        ]);
    }

    public function create(): void
    {
        $this->requireRole('process');
        $this->view('employees/form', [
            'title'        => 'New Employee',
            'emp'          => null,
            'departments'  => $this->repo->departments(),
            'designations' => $this->repo->designations(),
        ]);
    }

    public function edit(): void
    {
        $this->requireRole('process');
        $emp = $this->repo->raw((int) $this->input('id'));
        if (!$emp) {
            $this->flash('error', 'Employee not found.');
            $this->redirect('payroll/employees');
        }
        $this->view('employees/form', [
            'title'        => 'Edit Employee',
            'emp'          => $emp,
            'departments'  => $this->repo->departments(),
            'designations' => $this->repo->designations(),
        ]);
    }

    public function save(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $id = (int) $this->input('id', 0);
        $data = [
            'emp_code'       => $this->input('emp_code'),
            'first_name'     => $this->input('first_name'),
            'middle_name'    => $this->input('middle_name') ?: '',
            'last_name'      => $this->input('last_name') ?: '',
            'sex'            => $this->input('sex') !== '' ? (int) $this->input('sex') : null,
            'designation_id' => (int) $this->input('designation_id'),
            'department_id'  => (int) $this->input('department_id'),
            'email'          => $this->input('email') ?: null,
            'cell_no'        => $this->input('cell_no') ?: null,
            'joined_at'      => $this->input('joined_at') ?: null,
        ];

        if ($data['emp_code'] === '' || $data['first_name'] === '' || !$data['department_id']) {
            $this->flash('error', 'Employee code, first name and department are required.');
            $this->redirect($id ? 'payroll/employees/edit?id=' . $id : 'payroll/employees/new');
        }

        if ($id) {
            $this->repo->update($id, $data);
            $this->flash('success', 'Employee updated.');
        } else {
            $newId = $this->repo->create($data, (int) (Auth::id() ?? 0));
            $this->flash('success', 'Employee added. Set their salary structure next.');
            $this->redirect('payroll/structure?employee_id=' . $newId);
        }
        $this->redirect('payroll/employees');
    }

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }
}
