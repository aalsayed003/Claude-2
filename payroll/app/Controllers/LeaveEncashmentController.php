<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Repositories\LeaveEncashmentRepository;
use App\Repositories\SalaryStructureRepository;
use App\Repositories\EmployeeRepository;
use App\Repositories\PayrollRepository;

/**
 * Standalone leave encashment (legacy "Leave System -> Leave Encashment").
 *
 * A request names a number of days and a payroll month to pay in. It is priced
 * from the employee's salary structure. Once approved, the engine adds it to
 * that month's Leave Encashment earning and marks it paid when the month locks.
 */
class LeaveEncashmentController extends Controller
{
    private LeaveEncashmentRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new LeaveEncashmentRepository($this->db);
    }

    public function index(): void
    {
        $this->requireRole('view');
        $empId = (int) $this->input('employee_id', 0);
        $emp   = $empId ? (new PayrollRepository($this->db))->employee($empId) : null;

        $preview = null;
        if ($emp && $this->input('days')) {
            $structure = (new SalaryStructureRepository($this->db))
                ->effectiveFor($empId, date('Y-m-01'));
            $preview = $this->repo->priceDays($structure, (float) $this->input('days'));
        }

        $this->view('payroll/encashment', [
            'title'      => 'Leave Encashment',
            'emp'        => $emp,
            'requests'   => $empId ? $this->repo->forEmployee($empId) : [],
            'preview'    => $preview,
            'input'      => ['days' => $this->input('days', ''), 'month' => $this->input('month', date('Y-m'))],
            'employees'  => (new EmployeeRepository($this->db))->search('', 2000),
            'canProcess' => Auth::atLeast((string) Config::get('payroll.roles.process', 'fa')),
            'canApprove' => Auth::atLeast((string) Config::get('payroll.roles.approve', 'coo')),
        ]);
    }

    public function save(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $empId = (int) $this->input('employee_id');
        $days  = (float) $this->input('days', 0);
        if (!$empId || $days <= 0) {
            $this->flash('error', 'Employee and a positive number of days are required.');
            $this->redirect('payroll/encashment?employee_id=' . $empId);
        }
        $month = (string) $this->input('month', date('Y-m'));
        $structure = (new SalaryStructureRepository($this->db))->effectiveFor($empId, $month . '-01');
        $priced = $this->repo->priceDays($structure, $days);

        $this->repo->create([
            'employee_id'   => $empId,
            'days'          => $days,
            'day_rate'      => $priced['day_rate'],
            'amount'        => $priced['amount'],
            'payroll_month' => $month,
            'reason'        => $this->input('reason') ?: null,
        ], $this->userName());

        $this->flash('success', sprintf('Encashment of %s days (%s) requested for %s — pending approval.',
            $days, money($priced['amount']), date('F Y', strtotime($month . '-01'))));
        $this->redirect('payroll/encashment?employee_id=' . $empId);
    }

    public function setState(): void
    {
        $id  = (int) $this->input('encash_id');
        $req = $this->repo->find($id);
        if (!$req) {
            $this->flash('error', 'Request not found.');
            $this->redirect('payroll/encashment');
        }
        $state = (int) $this->input('state');
        // Approving is an approver action; cancelling is a processor action.
        if ($state === LeaveEncashmentRepository::APPROVED) {
            $this->requireRole('approve');
        } else {
            $this->requireRole('process');
        }
        $this->verifyCsrf();

        if ((int) $req['StateID'] === LeaveEncashmentRepository::PAID) {
            $this->flash('error', 'A paid encashment cannot be changed.');
            $this->redirect('payroll/encashment?employee_id=' . $req['EmployeeID']);
        }
        $this->repo->setState($id, $state, $this->userName());
        $this->flash('success', 'Encashment ' . strtolower(LeaveEncashmentRepository::STATE_LABELS[$state] ?? '') . '.');
        $this->redirect('payroll/encashment?employee_id=' . $req['EmployeeID']);
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
