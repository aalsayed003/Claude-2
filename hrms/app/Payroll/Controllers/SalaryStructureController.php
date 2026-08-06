<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\EmployeeRepository;
use App\Payroll\Repositories\SalaryStructureRepository;
use App\Payroll\Repositories\StatutoryRepository;
use App\Payroll\Repositories\PayrollRepository;

/**
 * Salary structure and statutory details per employee.
 *
 * A structure is effective-dated: saving from a month inserts a new row rather
 * than editing the old one, so last year's payroll still reconciles to last
 * year's structure.
 */
class SalaryStructureController extends Controller
{
    private SalaryStructureRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new SalaryStructureRepository($this->db);
    }

    public function index(): void
    {
        $this->requireRole('view');
        $q = (string) $this->input('q', '');
        $month = date('Y-m-01', strtotime(((string) $this->input('month', date('Y-m'))) . '-01'));

        $employees = (new EmployeeRepository($this->db))->search($q, 300);
        $structures = $this->repo->effectiveForAll($month);

        $rows = [];
        foreach ($employees as $e) {
            $s = $structures[(int) $e['id']] ?? null;
            $rows[] = $e + [
                'basic'     => $s ? SalaryStructureRepository::basicOf($s) : null,
                'gross'     => $s ? SalaryStructureRepository::grossOf($s) : null,
                'effective' => $s['CurrentMonth'] ?? null,
            ];
        }

        $this->view('payroll/structure_index', [
            'title' => 'Salary Structures',
            'rows'  => $rows,
            'q'     => $q,
            'month' => $month,
        ]);
    }

    public function edit(): void
    {
        $this->requireRole('process');
        $empId = (int) $this->input('employee_id');
        $emp   = (new PayrollRepository($this->db))->employee($empId);
        if (!$emp) {
            $this->flash('error', 'Employee not found.');
            $this->redirect('payroll/structures');
        }
        $month = date('Y-m-01', strtotime(((string) $this->input('month', date('Y-m'))) . '-01'));

        $this->view('payroll/structure_form', [
            'title'      => 'Salary Structure — ' . $emp['full_name'],
            'emp'        => $emp,
            'month'      => $month,
            'components' => SalaryStructureRepository::components(),
            'current'    => $this->repo->effectiveFor($empId, $month),
            'history'    => $this->repo->history($empId, 24),
            'statutory'  => (new StatutoryRepository($this->db))->forEmployee($empId),
            'banks'      => (new StatutoryRepository($this->db))->banks(),
        ]);
    }

    public function save(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $empId = (int) $this->input('employee_id');
        $month = (string) $this->input('effective_month', date('Y-m'));
        if (!$empId) {
            $this->flash('error', 'No employee selected.');
            $this->redirect('payroll/structures');
        }

        $values = [];
        foreach (SalaryStructureRepository::components() as $key => $c) {
            $values[$key] = (float) ($_POST['c'][$key] ?? 0);
        }
        $this->repo->save($empId, $month . '-01', $values, (int) (Auth::id() ?? 0));

        $this->flash('success', 'Salary structure saved, effective ' .
            date('F Y', strtotime($month . '-01')) . '.');
        $this->redirect('payroll/structure?employee_id=' . $empId . '&month=' . $month);
    }

    /**
     * Salary increment (legacy "Salary Details -> Salary Increment").
     * A friendlier front-end over the effective-dated structure: raise
     * components by a flat percentage, or by an amount on the basic, from a
     * chosen month. Applying it inserts a new structure row.
     */
    public function increment(): void
    {
        $this->requireRole('process');
        $empId = (int) $this->input('employee_id');
        $emp   = $empId ? (new PayrollRepository($this->db))->employee($empId) : null;
        if (!$emp) {
            $this->flash('error', 'Employee not found.');
            $this->redirect('payroll/structures');
        }
        $month   = (string) $this->input('month', date('Y-m', strtotime('+1 month')));
        $current = $this->repo->effectiveFor($empId, $month . '-01');

        $this->view('payroll/increment', [
            'title'      => 'Salary Increment — ' . $emp['full_name'],
            'emp'        => $emp,
            'month'      => $month,
            'components' => SalaryStructureRepository::components(),
            'current'    => $current,
            'history'    => $this->repo->history($empId, 12),
        ]);
    }

    public function applyIncrement(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $empId = (int) $this->input('employee_id');
        $month = (string) $this->input('effective_month', date('Y-m'));
        $emp   = $empId ? (new PayrollRepository($this->db))->employee($empId) : null;
        if (!$emp) {
            $this->flash('error', 'Employee not found.');
            $this->redirect('payroll/structures');
        }

        $current = $this->repo->effectiveFor($empId, $month . '-01');
        if (!$current) {
            $this->flash('error', 'No current structure to increment. Enter one first.');
            $this->redirect('payroll/structure?employee_id=' . $empId);
        }

        $mode = (string) $this->input('mode', 'percent');   // percent | amount | manual
        $pct  = (float) $this->input('percent', 0);
        $values = [];
        foreach (SalaryStructureRepository::components() as $key => $c) {
            $existing = (float) ($current[$c['structure']] ?? 0);
            if ($mode === 'manual') {
                $values[$key] = (float) ($_POST['c'][$key] ?? $existing);
            } elseif ($mode === 'amount' && $key === 'basic') {
                $values[$key] = $existing + (float) $this->input('amount', 0);
            } elseif ($mode === 'percent') {
                // Only components ticked to receive the rise are raised.
                $apply = isset($_POST['apply'][$key]);
                $values[$key] = $apply ? money_round($existing * (1 + $pct / 100)) : $existing;
            } else {
                $values[$key] = $existing;
            }
        }

        $this->repo->save($empId, $month . '-01', $values, (int) (Auth::id() ?? 0));
        $this->flash('success', 'Increment applied, effective ' . date('F Y', strtotime($month . '-01')) . '.');
        $this->redirect('payroll/structure?employee_id=' . $empId . '&month=' . $month);
    }

    /** Statutory + banking details (GOSI class, CPR, IBAN). */
    public function saveStatutory(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $empId = (int) $this->input('employee_id');
        if (!$empId) {
            $this->flash('error', 'No employee selected.');
            $this->redirect('payroll/structures');
        }

        (new StatutoryRepository($this->db))->save($empId, [
            'IsBahraini'   => $this->input('is_bahraini') ? 1 : 0,
            'CPR'          => $this->input('cpr') ?: null,
            'GosiNumber'   => $this->input('gosi_number') ?: null,
            'GosiJoinDate' => $this->input('gosi_join_date') ?: null,
            'ExcludeGosi'  => $this->input('exclude_gosi') ? 1 : 0,
            'LmraId'       => $this->input('lmra_id') ?: null,
            'BankID'       => $this->input('bank_id') ? (int) $this->input('bank_id') : null,
            'IBAN'         => $this->input('iban') ?: null,
            'AccountNo'    => $this->input('account_no') ?: null,
            'PaymentMode'  => (int) $this->input('payment_mode', 1),
            'JoiningDate'  => $this->input('joining_date') ?: null,
            'ContractType' => $this->input('contract_type') ? (int) $this->input('contract_type') : null,
        ], substr((string) (Auth::user()['username'] ?? 'system'), 0, 20));

        $this->flash('success', 'Statutory details saved.');
        $this->redirect('payroll/structure?employee_id=' . $empId);
    }

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }
}
