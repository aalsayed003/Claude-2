<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\LeaveProvisionRepository;

/**
 * Annual-leave provision — the accrued untaken-leave liability across all
 * active staff, valued on the latest basic salary, with a CSV export and
 * snapshotting like the indemnity provision.
 */
class LeaveProvisionController extends Controller
{
    private LeaveProvisionRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new LeaveProvisionRepository($this->db);
    }

    public function index(): void
    {
        $this->requireRole('view');
        $asOf   = (string) $this->input('as_of', date('Y-m-d'));
        $deptId = $this->input('department_id') ? (int) $this->input('department_id') : null;

        $data = $this->repo->compute($asOf, $deptId);

        if ($this->input('export') === 'csv') {
            $this->exportCsv($data);
            return;
        }

        $this->view('payroll/leave_provision', [
            'title'      => 'Leave Provision',
            'data'       => $data,
            'asOf'       => $data['as_of'],
            'deptId'     => $deptId,
            'departments'=> $this->db->all(
                "SELECT Id AS id, Name AS name FROM " . lt('department') . " WHERE Deleted = 0 ORDER BY Name"),
            'canProcess' => Auth::atLeast((string) Config::get('payroll.roles.process', 'fa')),
        ]);
    }

    public function snapshot(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $asOf = (string) $this->input('as_of', date('Y-m-d'));
        $data = $this->repo->compute($asOf, null);
        $n = $this->repo->saveSnapshot($data, substr((string) (Auth::user()['username'] ?? 'system'), 0, 20));
        $this->flash('success', "Leave provision snapshot saved for {$asOf}: {$n} employees, total "
            . money($data['totals']['amount']) . '.');
        $this->redirect('payroll/leave-provision?as_of=' . $asOf);
    }

    private function exportCsv(array $c): void
    {
        $out = [$this->csv(['Code', 'Employee', 'Department', 'Basic', 'Entitled', 'Used',
                            'Balance', 'Forfeited', 'Day rate', 'Provision'])];
        foreach ($c['rows'] as $r) {
            $out[] = $this->csv([$r['emp_code'], $r['full_name'], $r['dept_name'], $r['basic'],
                                 $r['entitled'], $r['used'], $r['balance'], $r['forfeited'],
                                 $r['day_rate'], $r['amount']]);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leave-provision-' . $c['as_of'] . '.csv"');
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

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }
}
