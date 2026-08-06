<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\SalaryHoldRepository;
use App\Payroll\Repositories\EmployeeRepository;
use App\Payroll\Repositories\PayrollRepository;

/**
 * Salary Hold / Release (legacy "Salary Hold Memo / Release Memo / Hold And
 * Release").
 *
 * Holding a month leaves the employee's calculated salary on the register but
 * out of the bank file. Releasing pays the accumulated held net in a chosen
 * later month as an arrear. The held figure is fixed when the held month is
 * locked, so it cannot drift.
 */
class SalaryHoldController extends Controller
{
    private SalaryHoldRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new SalaryHoldRepository($this->db);
    }

    public function index(): void
    {
        $this->requireRole('view');

        $empId = (int) $this->input('employee_id', 0);
        $emp   = $empId ? (new PayrollRepository($this->db))->employee($empId) : null;

        $this->view('payroll/holds', [
            'title'      => 'Salary Hold & Release',
            'emp'        => $emp,
            'holds'      => $empId ? $this->repo->forEmployee($empId) : [],
            'heldList'   => $this->repo->heldList(),
            'employees'  => (new EmployeeRepository($this->db))->search('', 2000),
            'suggest'    => date('Y-m'),
            'canProcess' => Auth::atLeast((string) Config::get('payroll.roles.process', 'fa')),
        ]);
    }

    public function hold(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $empId = (int) $this->input('employee_id');
        $month = (string) $this->input('hold_month', date('Y-m'));
        if (!$empId) {
            $this->flash('error', 'No employee selected.');
            $this->redirect('payroll/holds');
        }
        $this->repo->create($empId, $month . '-01',
            $this->input('reason') ?: null, $this->input('memo') ?: null, $this->userName());
        $this->flash('success', 'Salary held for ' . date('F Y', strtotime($month . '-01')) .
            '. The employee is excluded from that month\'s bank file.');
        $this->redirect('payroll/holds?employee_id=' . $empId);
    }

    public function release(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $holdId = (int) $this->input('hold_id');
        $hold   = $this->repo->find($holdId);
        if (!$hold || (int) $hold['StateID'] !== SalaryHoldRepository::HELD) {
            $this->flash('error', 'Only a held salary can be released.');
            $this->redirect('payroll/holds');
        }
        if ($hold['HeldNet'] === null) {
            $this->flash('error', 'The held month has not been locked yet, so the held amount is not final. '
                . 'Lock ' . date('F Y', strtotime((string) $hold['HoldMonth'])) . ' first.');
            $this->redirect('payroll/holds?employee_id=' . $hold['EmployeeID']);
        }
        $releaseMonth = (string) $this->input('release_month', date('Y-m'));
        $this->repo->release($holdId, $releaseMonth . '-01', $this->input('memo') ?: null, $this->userName());
        $this->flash('success', 'Released ' . money($hold['HeldNet']) . ' into ' .
            date('F Y', strtotime($releaseMonth . '-01')) . ' as an arrear.');
        $this->redirect('payroll/holds?employee_id=' . $hold['EmployeeID']);
    }

    public function cancel(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $holdId = (int) $this->input('hold_id');
        $hold   = $this->repo->find($holdId);
        if (!$hold) {
            $this->flash('error', 'Hold not found.');
            $this->redirect('payroll/holds');
        }
        $this->repo->cancel($holdId);
        $this->flash('success', 'Hold cancelled.');
        $this->redirect('payroll/holds?employee_id=' . $hold['EmployeeID']);
    }

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }

    private function userName(): string
    {
        return substr((string) (Auth::user()['username'] ?? 'system'), 0, 20);
    }
}
