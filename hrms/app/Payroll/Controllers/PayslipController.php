<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\PayrollRepository;
use App\Payroll\Repositories\EmployeeRepository;
use App\Payroll\Services\PayrollEngine;

/**
 * Payslips.
 *
 * An employee always sees their own; FA and above may see anyone's. Once a
 * month has been posted the slip is rendered from the register, so it matches
 * what was paid to the fils. For a month that has not been calculated yet, an
 * FA can preview the same breakdown live from the engine — clearly marked as a
 * preview, never as a payslip.
 */
class PayslipController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $repo  = new PayrollRepository($this->db);
        $user  = Auth::user();
        $canPickAnyone = Auth::atLeast((string) Config::get('payroll.roles.view', 'fa'));

        $empId = $canPickAnyone
            ? (int) $this->input('employee_id', $user['employee_id'] ?? 0)
            : (int) ($user['employee_id'] ?? 0);
        $month = date('Y-m-01', strtotime(((string) $this->input('month', date('Y-m'))) . '-01'));

        $emp = $empId ? $repo->employee($empId) : null;
        $row = ($emp) ? $repo->registerRow($empId, $month) : null;
        $preview = null;

        if ($emp && !$row && $canPickAnyone) {
            try {
                $preview = (new PayrollEngine($this->db))->preview($emp, $month);
            } catch (\Throwable $e) {
                $preview = null;
            }
        }

        $this->view('payroll/payslip', [
            'title'      => 'Payslip',
            'emp'        => $emp,
            'month'      => $month,
            'row'        => $row,
            'preview'    => $preview,
            'run'        => $repo->findRunByMonth($month),
            'components' => (array) Config::get('payroll.components', []),
            'employees'  => $canPickAnyone ? (new EmployeeRepository($this->db))->search('', 2000) : [],
            'canPickAnyone' => $canPickAnyone,
        ]);
    }

    /** Printable single-page slip. */
    public function print(): void
    {
        Auth::require();
        $repo = new PayrollRepository($this->db);
        $user = Auth::user();
        $canPickAnyone = Auth::atLeast((string) Config::get('payroll.roles.view', 'fa'));

        $empId = $canPickAnyone
            ? (int) $this->input('employee_id')
            : (int) ($user['employee_id'] ?? 0);
        $month = date('Y-m-01', strtotime(((string) $this->input('month', date('Y-m'))) . '-01'));

        $emp = $empId ? $repo->employee($empId) : null;
        $row = $emp ? $repo->registerRow($empId, $month) : null;
        if (!$row) {
            $this->flash('error', 'No posted payslip for that month.');
            $this->redirect('payroll/payslip');
        }

        $this->view('payroll/payslip_print', [
            'title'      => 'Payslip',
            'emp'        => $emp,
            'month'      => $month,
            'row'        => $row,
            'components' => (array) Config::get('payroll.components', []),
        ], 'blank');
    }
}
