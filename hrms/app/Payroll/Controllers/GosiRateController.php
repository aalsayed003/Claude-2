<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\StatutoryRepository;
use App\Payroll\Repositories\PayrollRepository;
use App\Payroll\Services\GosiCalculator;

/**
 * GOSI / SIO rate master. Rates are effective-dated, so changing them adds a new
 * row from a date forward and never rewrites months already paid. Categories:
 * Bahraini (social insurance + unemployment), Bahraini retiree (SI only), Expat.
 */
class GosiRateController extends Controller
{
    public function index(): void
    {
        $this->requireRole('process');
        $repo = new StatutoryRepository($this->db);
        $this->view('payroll/gosi_rates', [
            'title' => 'GOSI Rates',
            'rates' => $repo->gosiRates(),
        ]);
    }

    public function save(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();

        $eff = $this->input('effective_from');
        $cat = $this->input('category');
        if (!$eff || !in_array($cat, ['bahraini', 'retiree', 'expat'], true)) {
            $this->flash('error', 'Choose a category and an effective-from date.');
            $this->redirect('payroll/gosi-rates');
        }
        (new StatutoryRepository($this->db))->saveGosiRate([
            'effective_from' => $eff,
            'category'       => $cat,
            'social_emp_pct' => $this->input('social_emp_pct', 0),
            'unemp_emp_pct'  => $this->input('unemp_emp_pct', 0),
            'social_er_pct'  => $this->input('social_er_pct', 0),
            'unemp_er_pct'   => $this->input('unemp_er_pct', 0),
            'min_wage'       => $this->input('min_wage', ''),
            'max_wage'       => $this->input('max_wage', ''),
            'notes'          => $this->input('notes') ?: null,
        ]);
        $this->flash('success', 'GOSI rate saved. It applies to payroll months on or after the effective date.');
        $this->redirect('payroll/gosi-rates');
    }

    /**
     * Monthly GOSI reconciliation: employee + employer contributions per
     * category, so the total (which the SIO invoices) can be checked against
     * the posted register. The employer share is never deducted from staff — it
     * is the hospital's cost — and is derived from the stored employee GOSI and
     * the category's employee/employer ratio (same wage + cap).
     */
    public function report(): void
    {
        $this->requireRole('process');
        $payroll = new PayrollRepository($this->db);
        $stat    = new StatutoryRepository($this->db);

        $runs  = $payroll->runs(24);
        $month = $this->input('payroll_month');
        if (!$month) {
            $month = $runs ? date('Y-m', strtotime((string) $runs[0]['PayrollMonth'])) : date('Y-m');
        }
        $monthStart = date('Y-m-01', strtotime($month . '-01'));

        $gosiCol = (string) Config::get('payroll.components.gosi.register', 'GOSI');
        $statAll = $stat->all();
        $labels  = ['bahraini' => 'Bahraini', 'retiree' => 'Bahraini retiree', 'expat' => 'Expat'];
        $cats = [];
        foreach ($labels as $k => $lbl) {
            $cats[$k] = ['label' => $lbl, 'count' => 0, 'wage' => 0.0, 'employee' => 0.0,
                         'employer' => 0.0, 'emp_pct' => 0.0, 'er_pct' => 0.0];
        }

        try {
            $rows = $payroll->register($monthStart);
        } catch (\Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $r) {
            $empId   = (int) ($r['Empid'] ?? 0);
            $empGosi = (float) ($r[$gosiCol] ?? 0);
            if ($empGosi <= 0) {
                continue;   // no contribution (excluded / expat with 0 etc.)
            }
            $cat  = GosiCalculator::category($statAll[$empId] ?? null);
            $rate = $stat->gosiRate($cat, $monthStart);
            $empPct = (float) $rate['employee_pct'];
            $erPct  = (float) $rate['employer_pct'];
            $employer = $empPct > 0 ? money_round($empGosi * $erPct / $empPct) : 0.0;
            $wage     = $empPct > 0 ? money_round($empGosi * 100 / $empPct) : 0.0;

            $cats[$cat]['count']++;
            $cats[$cat]['wage']     += $wage;
            $cats[$cat]['employee'] += $empGosi;
            $cats[$cat]['employer'] += $employer;
            $cats[$cat]['emp_pct']   = $empPct;
            $cats[$cat]['er_pct']    = $erPct;
        }

        $grand = ['count' => 0, 'wage' => 0.0, 'employee' => 0.0, 'employer' => 0.0];
        foreach ($cats as $c) {
            $grand['count']    += $c['count'];
            $grand['wage']     += $c['wage'];
            $grand['employee'] += $c['employee'];
            $grand['employer'] += $c['employer'];
        }
        $grand['total'] = $grand['employee'] + $grand['employer'];

        $this->view('payroll/gosi_report', [
            'title' => 'GOSI Reconciliation',
            'month' => $month,
            'runs'  => $runs,
            'cats'  => $cats,
            'grand' => $grand,
        ]);
    }

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }
}
