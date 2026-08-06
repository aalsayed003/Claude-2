<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\PayrollRepository;
use App\Payroll\Repositories\EmployeeRepository;
use App\Payroll\Services\SettlementCalculator;

/**
 * End-of-service settlements: indemnity, leave encashment, notice, ticket, and
 * recovery of any outstanding loan.
 *
 * The calculation is shown before anything is saved, with every input visible,
 * because a settlement is a one-off figure that someone has to defend.
 */
class SettlementController extends Controller
{
    private SettlementCalculator $calc;

    public function __construct()
    {
        parent::__construct();
        $this->calc = new SettlementCalculator($this->db);
    }

    public function index(): void
    {
        $this->requireRole('view');

        $empId = (int) $this->input('employee_id', 0);
        $emp   = $empId ? (new PayrollRepository($this->db))->employee($empId) : null;

        $result = null;
        $error  = null;
        if ($emp && $this->input('last_working_day')) {
            try {
                $result = $this->calc->compute($emp, [
                    'last_working_day' => (string) $this->input('last_working_day'),
                    'leave_days'       => $this->input('leave_days'),
                    'notice_amount'    => (float) $this->input('notice_amount', 0),
                    'ticket_amount'    => (float) $this->input('ticket_amount', 0),
                    'other_earnings'   => (float) $this->input('other_earnings', 0),
                    'other_deduction'  => (float) $this->input('other_deduction', 0),
                ]);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->view('payroll/settlement', [
            'title'     => 'End-of-Service Settlement',
            'emp'       => $emp,
            'result'    => $result,
            'error'     => $error,
            'saved'     => $emp ? $this->calc->forEmployee((int) $emp['id']) : [],
            'employees' => (new EmployeeRepository($this->db))->search('', 2000),
            'input'     => [
                'last_working_day' => $this->input('last_working_day', ''),
                'leave_days'       => $this->input('leave_days', ''),
                'notice_amount'    => $this->input('notice_amount', ''),
                'ticket_amount'    => $this->input('ticket_amount', ''),
                'other_earnings'   => $this->input('other_earnings', ''),
                'other_deduction'  => $this->input('other_deduction', ''),
                'reason_id'        => $this->input('reason_id', 1),
            ],
            'reasons'   => [1 => 'Resignation', 2 => 'Termination', 3 => 'Contract end',
                            4 => 'Retirement', 5 => 'Death in service'],
            'canProcess'=> Auth::atLeast((string) Config::get('payroll.roles.process', 'fa')),
        ]);
    }

    public function save(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $empId = (int) $this->input('employee_id');
        $emp   = $empId ? (new PayrollRepository($this->db))->employee($empId) : null;
        if (!$emp) {
            $this->flash('error', 'Employee not found.');
            $this->redirect('payroll/settlement');
        }

        try {
            $r = $this->calc->compute($emp, [
                'last_working_day' => (string) $this->input('last_working_day'),
                'leave_days'       => $this->input('leave_days'),
                'notice_amount'    => (float) $this->input('notice_amount', 0),
                'ticket_amount'    => (float) $this->input('ticket_amount', 0),
                'other_earnings'   => (float) $this->input('other_earnings', 0),
                'other_deduction'  => (float) $this->input('other_deduction', 0),
            ]);
            $this->calc->save($r, (int) $this->input('reason_id', 1),
                substr((string) (Auth::user()['username'] ?? 'system'), 0, 20),
                $this->input('remarks') ?: null);
            $this->flash('success', 'Settlement saved as a draft — net ' . money($r['net']) . '.');
        } catch (\Throwable $e) {
            $this->flash('error', 'Could not save the settlement: ' . $e->getMessage());
        }
        $this->redirect('payroll/settlement?employee_id=' . $empId);
    }

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }
}
