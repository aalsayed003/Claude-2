<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\StatutoryRepository;

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

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }
}
