<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\LoanRepository;
use App\Payroll\Repositories\EmployeeRepository;
use App\Payroll\Repositories\PayrollRepository;

/**
 * Staff loans and salary advances.
 *
 * The schedule is deliberately simple — equal installments from a start month —
 * because that is what the register's single deduction column can represent.
 * Recovery is only recorded when a payroll month is LOCKED, so a draft can be
 * recalculated as often as needed without moving a balance.
 */
class LoanController extends Controller
{
    private LoanRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new LoanRepository($this->db);
    }

    public function index(): void
    {
        $this->requireRole('view');

        $empId = (int) $this->input('employee_id', 0);
        $emp   = $empId ? (new PayrollRepository($this->db))->employee($empId) : null;

        $loans = [];
        if ($empId) {
            foreach ($this->repo->forEmployee($empId) as $l) {
                $l['outstanding'] = money_round((float) $l['PrincipalAmount'] - (float) $l['RecoveredAmount']);
                $l['installments'] = $this->repo->installments((int) $l['LoanID']);
                $loans[] = $l;
            }
        }

        $this->view('payroll/loans', [
            'title'     => 'Loans & Advances',
            'emp'       => $emp,
            'loans'     => $loans,
            'employees' => (new EmployeeRepository($this->db))->search('', 2000),
            'types'     => LoanRepository::TYPE_LABEL,
            'canProcess'=> Auth::atLeast((string) Config::get('payroll.roles.process', 'fa')),
        ]);
    }

    public function save(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $empId       = (int) $this->input('employee_id');
        $principal   = (float) $this->input('principal', 0);
        $installment = (float) $this->input('installment', 0);
        $count       = (int) $this->input('installments', 0);

        if (!$empId || $principal <= 0 || $installment <= 0 || $count <= 0) {
            $this->flash('error', 'Employee, principal, installment and number of installments are all required.');
            $this->redirect('payroll/loans?employee_id=' . $empId);
        }
        if ($installment * $count < $principal) {
            $this->flash('error', sprintf(
                'The schedule recovers only %s of a %s loan. Raise the installment or the count.',
                money($installment * $count), money($principal)));
            $this->redirect('payroll/loans?employee_id=' . $empId);
        }

        $this->repo->create([
            'employee_id' => $empId,
            'loan_type'   => (int) $this->input('loan_type', 1),
            'reference'   => $this->input('reference') ?: null,
            'principal'   => $principal,
            'installment' => $installment,
            'start_month' => (string) $this->input('start_month', date('Y-m')),
            'installments'=> $count,
            'remarks'     => $this->input('remarks') ?: null,
        ], substr((string) (Auth::user()['username'] ?? 'system'), 0, 20));

        $this->flash('success', 'Loan recorded. Recovery starts in ' .
            date('F Y', strtotime($this->input('start_month') . '-01')) . '.');
        $this->redirect('payroll/loans?employee_id=' . $empId);
    }

    public function setState(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $loanId = (int) $this->input('loan_id');
        $state  = (int) $this->input('state');
        $loan   = $this->repo->find($loanId);
        if (!$loan) {
            $this->flash('error', 'Loan not found.');
            $this->redirect('payroll/loans');
        }
        $this->repo->setState($loanId, $state);
        $this->flash('success', 'Loan updated.');
        $this->redirect('payroll/loans?employee_id=' . $loan['EmployeeID']);
    }

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }
}
