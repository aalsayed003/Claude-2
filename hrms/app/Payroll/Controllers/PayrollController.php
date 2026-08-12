<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\PayrollRepository;
use App\Payroll\Repositories\StatutoryRepository;
use App\Payroll\Services\PayrollEngine;
use App\Payroll\Services\WpsExporter;
use App\Payroll\Services\BankFile;

/**
 * Payroll runs: open a month, calculate it, review the register, approve,
 * lock, and produce the bank file.
 *
 * A month moves Draft -> Calculated -> Approved -> Locked. Recalculating is
 * free while the run is a draft and refused once it is approved, so the
 * register behind a payslip an employee has already seen cannot change
 * underneath them.
 */
class PayrollController extends Controller
{
    private PayrollRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new PayrollRepository($this->db);
    }

    public function index(): void
    {
        $this->requireView();
        $this->view('payroll/index', [
            'title'  => 'Payroll',
            'runs'   => $this->repo->runs(),
            'suggest'=> $this->suggestedMonth(),
            'canProcess' => $this->allowed('process'),
        ]);
    }

    /** Open a payroll month (creates the run header). */
    public function create(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $month  = (string) $this->input('payroll_month', date('Y-m'));
        $period = period_of_payroll_month($month);
        [$from, $to] = period_bounds($period);

        try {
            $run = $this->repo->createRun($month . '-01', $from, $to, $this->userName());
        } catch (\Throwable $e) {
            $this->flash('error', 'Could not open the payroll month: ' . $e->getMessage());
            $this->redirect('payroll');
            return;
        }
        $this->flash('success', 'Payroll month ' . date('F Y', strtotime($month . '-01')) .
                                ' opened for the cycle ' . $from . ' .. ' . $to . '.');
        $this->redirect('payroll/run?id=' . $run['RunID']);
    }

    public function show(): void
    {
        $this->requireView();
        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run) {
            $this->flash('error', 'Payroll run not found.');
            $this->redirect('payroll');
        }
        $month = date('Y-m-01', strtotime((string) $run['PayrollMonth']));

        // Build the page defensively: a data/schema problem should show a clear
        // message (which is safe to send on for support), not a blank 500.
        try {
            $employees  = $this->repo->payableEmployees($month);
            $exceptions = (new StatutoryRepository($this->db))->exceptions($employees);
            $held       = (new \App\Payroll\Repositories\SalaryHoldRepository($this->db))->activeForMonth($month);
            $data = [
                'title'      => 'Payroll — ' . date('F Y', strtotime($month)),
                'run'        => $run,
                'month'      => $month,
                'totals'     => $this->repo->registerTotals($month),
                'headcount'  => count($employees),
                'heldCount'  => count($held),
                'exceptions' => $exceptions,
                'audit'      => $this->repo->auditTrail((int) $run['RunID'], 30),
                'wps'        => (new WpsExporter($this->db))->history((int) $run['RunID']),
                'canProcess' => $this->allowed('process'),
                'canApprove' => $this->allowed('approve'),
                'editable'   => $this->repo->isEditable($run),
            ];
        } catch (\Throwable $e) {
            $this->flash('error', 'Could not open this payroll run: ' . $e->getMessage());
            $this->redirect('payroll');
            return;
        }
        $this->view('payroll/run', $data);
    }

    public function calculate(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run) {
            $this->flash('error', 'Payroll run not found.');
            $this->redirect('payroll');
        }
        $deptId = $this->input('department_id') ? (int) $this->input('department_id') : null;

        try {
            $engine = new PayrollEngine($this->db);
            $r = $engine->calculate($run, $deptId, (int) (Auth::id() ?? 0), $this->userName());
            $msg = sprintf('Calculated %d employees — earnings %s, deductions %s, net %s.',
                $r['employees'], money($r['earnings']), money($r['deductions']), money($r['net']));
            if ($r['skipped']) {
                $msg .= ' ' . count($r['skipped']) . ' skipped (no salary structure).';
            }
            $this->flash('success', $msg);
        } catch (\Throwable $e) {
            $this->flash('error', 'Calculation failed: ' . $e->getMessage());
        }
        $this->redirect('payroll/run?id=' . $run['RunID']);
    }

    public function approve(): void
    {
        $this->requireRole('approve');
        $this->verifyCsrf();
        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run || (int) $run['StateID'] !== PayrollRepository::CALCULATED) {
            $this->flash('error', 'Only a calculated run can be approved.');
            $this->redirect('payroll');
        }
        $this->repo->setRunState((int) $run['RunID'], PayrollRepository::APPROVED,
            $this->userName(), $this->input('remarks') ?: null);
        $this->flash('success', 'Payroll approved. The register is now read-only.');
        $this->redirect('payroll/run?id=' . $run['RunID']);
    }

    /**
     * Lock the month. This is the point at which loan installments are posted
     * — recalculating a draft any number of times never touches a balance.
     */
    public function lock(): void
    {
        $this->requireRole('approve');
        $this->verifyCsrf();
        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run || (int) $run['StateID'] !== PayrollRepository::APPROVED) {
            $this->flash('error', 'Only an approved run can be locked.');
            $this->redirect('payroll');
        }

        $month = date('Y-m-01', strtotime((string) $run['PayrollMonth']));
        $loans = new \App\Payroll\Repositories\LoanRepository($this->db);
        $posted = 0;
        foreach ($loans->dueByEmployee($month) as $empDue) {
            foreach ($empDue['_loans'] ?? [] as $l) {
                $loans->postInstallment($l['loan_id'], $month, $l['amount'], (int) $run['RunID']);
                $posted++;
            }
        }

        // Stamp the actual withheld net onto held rows, so a release later pays
        // the right figure, and mark leave encashments paid.
        $holds = new \App\Payroll\Repositories\SalaryHoldRepository($this->db);
        foreach ($holds->activeForMonth($month) as $empId => $h) {
            $reg = $this->repo->registerRow($empId, $month);
            if ($reg) {
                $holds->stampHeldNet($empId, $month, (float) $reg['NetPayment']);
            }
        }
        $encashPaid = (new \App\Payroll\Repositories\LeaveEncashmentRepository($this->db))
            ->markPaidForMonth($month, (int) $run['RunID']);

        $this->repo->setRunState((int) $run['RunID'], PayrollRepository::LOCKED,
            $this->userName(), "{$posted} loan installments posted, {$encashPaid} encashments paid");
        $this->flash('success', "Payroll locked. {$posted} loan installments posted"
            . ($encashPaid ? ", {$encashPaid} leave encashments paid" : '') . '.');
        $this->redirect('payroll/run?id=' . $run['RunID']);
    }

    public function reopen(): void
    {
        $this->requireRole('approve');
        $this->verifyCsrf();
        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run || (int) $run['StateID'] !== PayrollRepository::APPROVED) {
            $this->flash('error', 'Only an approved run can be reopened. A locked run stays locked.');
            $this->redirect('payroll');
        }
        $this->repo->setRunState((int) $run['RunID'], PayrollRepository::DRAFT,
            $this->userName(), $this->input('remarks') ?: 'reopened for correction');
        $this->flash('success', 'Run reopened as a draft.');
        $this->redirect('payroll/run?id=' . $run['RunID']);
    }

    /** The monthly register — the payroll sheet the FA signs. */
    public function register(): void
    {
        $this->requireView();
        $month  = date('Y-m-01', strtotime(((string) $this->input('month', date('Y-m'))) . '-01'));
        $deptId = $this->input('department_id') ? (int) $this->input('department_id') : null;
        $rows   = $this->repo->register($month, $deptId);

        if ($this->input('export') === 'csv') {
            $this->exportRegisterCsv($month, $rows);
            return;
        }

        $this->view('payroll/register', [
            'title'       => 'Payroll Register — ' . date('F Y', strtotime($month)),
            'month'       => $month,
            'rows'        => $rows,
            'departments' => $this->db->all(
                "SELECT Id AS id, Name AS name FROM " . lt('department') . " WHERE Deleted = 0 ORDER BY Name"),
            'departmentId'=> $deptId,
            'run'         => $this->repo->findRunByMonth($month),
        ]);
    }

    /** Build and download the WPS / bank transfer file for a locked run. */
    /** Bank of Payment register + per-bank transfer files with QA checks. */
    public function bank(): void
    {
        $this->requireRole('approve');
        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run) {
            $this->flash('error', 'Payroll run not found.');
            $this->redirect('payroll');
        }
        if ((int) $run['StateID'] < PayrollRepository::APPROVED) {
            $this->flash('error', 'Approve the payroll before producing bank files.');
            $this->redirect('payroll/run?id=' . $run['RunID']);
        }
        $bf = new BankFile($this->db);
        $this->view('payroll/bank', [
            'title' => 'Bank of Payment — ' . date('F Y', strtotime((string) $run['PayrollMonth'])),
            'run'   => $run,
            'reg'   => $bf->register($run),
            'files' => $bf->transferFiles($run),
        ]);
    }

    /** Download one bank's transfer file. */
    public function bankFile(): void
    {
        $this->requireRole('approve');
        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run) { http_response_code(404); echo 'Payroll run not found.'; return; }
        $files = (new BankFile($this->db))->transferFiles($run);
        $group = (string) $this->input('group');
        if (!isset($files[$group])) {
            $this->flash('error', 'No transfer file for that bank.');
            $this->redirect('payroll/bank?id=' . $run['RunID']);
        }
        $f = $files[$group];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $f['filename'] . '"');
        echo $f['content'];
        exit;
    }

    public function wps(): void
    {
        $this->requireRole('approve');
        $run = $this->repo->findRun((int) $this->input('id'));
        if (!$run) {
            $this->flash('error', 'Payroll run not found.');
            $this->redirect('payroll');
        }
        if ((int) $run['StateID'] < PayrollRepository::APPROVED) {
            $this->flash('error', 'Approve the payroll before producing the bank file.');
            $this->redirect('payroll/run?id=' . $run['RunID']);
        }

        $exporter = new WpsExporter($this->db);
        $file = $exporter->build($run);

        if ($this->input('confirm') !== '1') {
            $this->view('payroll/wps', [
                'title' => 'Bank file — ' . date('F Y', strtotime((string) $run['PayrollMonth'])),
                'run'   => $run,
                'file'  => $file,
                'history' => $exporter->history((int) $run['RunID']),
            ]);
            return;
        }

        $exporter->record($run, $file, $this->userName());
        $this->repo->audit((int) $run['RunID'], 6, 'wps-export', $this->userName(),
            $file['filename'] . ' — ' . $file['records'] . ' records, ' . money($file['total']));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
        echo $file['content'];
        exit;
    }

    // ------------------------------------------------------------- helpers --

    private function exportRegisterCsv(string $month, array $rows): void
    {
        $cfg  = (array) Config::get('payroll.components', []);
        $cols = [];
        foreach ($cfg as $key => $c) {
            $cols[$key] = $c;
        }

        $head = ['Employee Code', 'Employee Name', 'Department', 'Payable Days',
                 'Present', 'Absent', 'Paid Leave'];
        foreach ($cols as $c) {
            $head[] = $c['label'];
        }
        $head = array_merge($head, ['Total Earnings', 'Total Deductions', 'Net Payment']);

        $out = [$this->csv($head)];
        foreach ($rows as $r) {
            $line = [
                $r['emp_code'], $r['emp_name'], $r['dept_name'],
                $r['payabledays'], $r['NoofDaysattended'], $r['absentdays'], $r['LEAVE'],
            ];
            foreach ($cols as $c) {
                $line[] = money_round((float) ($r[$c['register']] ?? 0));
            }
            $line[] = money_round((float) $r['TotalEarnings']);
            $line[] = money_round((float) $r['TotalDeduction']);
            $line[] = money_round((float) $r['NetPayment']);
            $out[] = $this->csv($line);
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payroll-register-' . date('Y-m', strtotime($month)) . '.csv"');
        echo implode("\r\n", $out) . "\r\n";
        exit;
    }

    private function csv(array $cells): string
    {
        return implode(',', array_map(function ($c) {
            $c = (string) $c;
            return preg_match('/[",\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }, $cells));
    }

    /** The month most likely to be opened next: the one after the latest run. */
    private function suggestedMonth(): string
    {
        $runs = $this->repo->runs(1);
        if (!$runs) {
            return payroll_month_of_period(period_of(date('Y-m-d')));
        }
        return date('Y-m-01', strtotime((string) $runs[0]['PayrollMonth'] . ' +1 month'));
    }

    private function allowed(string $action): bool
    {
        return Auth::atLeast((string) Config::get('payroll.roles.' . $action, 'fa'));
    }

    private function requireView(): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.view', 'fa'));
    }

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }

    private function userName(): string
    {
        $u = Auth::user();
        return substr((string) ($u['username'] ?? 'system'), 0, 20);
    }
}
