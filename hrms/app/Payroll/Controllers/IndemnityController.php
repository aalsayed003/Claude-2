<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\IndemnityProvisionRepository;

/**
 * Indemnity Provision — the accrued end-of-service liability for every active
 * employee as of a reporting date, with optional movement against a saved
 * snapshot and a CSV export for the accounts.
 */
class IndemnityController extends Controller
{
    private IndemnityProvisionRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new IndemnityProvisionRepository($this->db);
    }

    public function index(): void
    {
        $this->requireRole('view');

        $asOf   = (string) $this->input('as_of', date('Y-m-d'));
        $deptId = $this->input('department_id') ? (int) $this->input('department_id') : null;
        $compare = (string) $this->input('compare', '');

        $computed = $this->repo->compute($asOf, $deptId);

        // Optional movement vs a saved snapshot.
        $prior = $compare !== '' ? $this->repo->snapshotMap($compare) : [];
        $movementTotal = 0.0;
        if ($prior) {
            foreach ($computed['rows'] as &$r) {
                $was = $prior[$r['id']] ?? 0.0;
                $r['prior']    = $was;
                $r['movement'] = money_round($r['amount'] - $was);
                $movementTotal += $r['movement'];
            }
            unset($r);
        }

        if ($this->input('export') === 'csv') {
            $this->exportCsv($computed, (bool) $prior);
            return;
        }

        $this->view('payroll/indemnity', [
            'title'      => 'Indemnity Provision',
            'data'       => $computed,
            'asOf'       => $computed['as_of'],
            'deptId'     => $deptId,
            'compare'    => $compare,
            'hasMovement'=> (bool) $prior,
            'movementTotal' => money_round($movementTotal),
            'snapshots'  => $this->repo->snapshotDates(),
            'departments'=> $this->db->all(
                "SELECT Id AS id, Name AS name FROM " . lt('department') . " WHERE Deleted = 0 ORDER BY Name"),
            'canProcess' => Auth::atLeast((string) Config::get('payroll.roles.process', 'fa')),
            'basisLabel' => ucfirst((string) $computed['wage_basis']),
        ]);
    }

    /** Save the current computation as the snapshot for its reporting date. */
    public function snapshot(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $asOf   = (string) $this->input('as_of', date('Y-m-d'));
        $deptId = $this->input('department_id') ? (int) $this->input('department_id') : null;
        if ($deptId) {
            $this->flash('error', 'Snapshot the whole hospital, not a single department — clear the department filter first.');
            $this->redirect('payroll/indemnity?as_of=' . $asOf);
        }

        $computed = $this->repo->compute($asOf, null);
        $n = $this->repo->saveSnapshot($computed, substr((string) (Auth::user()['username'] ?? 'system'), 0, 20));
        $this->flash('success', "Provision snapshot saved for {$asOf}: {$n} employees, total "
            . money($computed['totals']['amount']) . '.');
        $this->redirect('payroll/indemnity?as_of=' . $asOf);
    }

    private function exportCsv(array $c, bool $movement): void
    {
        $head = ['Employee Code', 'Employee', 'Department', 'Joining Date', 'Service (yrs)',
                 'Wage', 'Accrued Days', 'Provision'];
        if ($movement) {
            $head[] = 'Prior';
            $head[] = 'Movement';
        }
        $out = [$this->csv($head)];
        foreach ($c['rows'] as $r) {
            $line = [$r['emp_code'], $r['full_name'], $r['dept_name'], $r['joining'],
                     $r['years'], $r['wage'], $r['days'], $r['amount']];
            if ($movement) {
                $line[] = $r['prior'] ?? 0;
                $line[] = $r['movement'] ?? 0;
            }
            $out[] = $this->csv($line);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="indemnity-provision-' . $c['as_of'] . '.csv"');
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
