<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Repositories\PayrollRepository;
use App\Services\RosterLink;

/**
 * Payroll home: the roster-link status, the latest runs, and quick links.
 */
class DashboardController extends Controller
{
    public function index(): void
    {
        Auth::require();

        $repo   = new PayrollRepository($this->db);
        $canView = Auth::atLeast((string) Config::get('payroll.roles.view', 'fa'));

        $counts = [];
        $runs   = [];
        if ($canView) {
            try {
                $runs = $repo->runs(6);
                $counts['employees'] = (int) $this->db->value(
                    "SELECT COUNT(*) FROM " . lt('employee') . " WHERE Deleted = 0");
                $counts['structures'] = (int) $this->safe(fn() => $this->db->value(
                    "SELECT COUNT(DISTINCT Empid) FROM " . pt('structure') .
                    " WHERE (Deleted = 0 OR Deleted IS NULL)"));
                $counts['held'] = (int) $this->safe(fn() => $this->db->value(
                    "SELECT COUNT(*) FROM " . pt('salary_hold') . " WHERE StateID = 1"));
                $counts['loans'] = (int) $this->safe(fn() => $this->db->value(
                    "SELECT COUNT(*) FROM " . pt('loan') . " WHERE StateID = 1"));
            } catch (\Throwable $e) {
                // fresh install before schema is applied — show the empty state
            }
        }

        $this->view('dashboard/index', [
            'title'      => 'Payroll',
            'link'       => RosterLink::status(),
            'runs'       => $runs,
            'counts'     => $counts,
            'canView'    => $canView,
        ]);
    }

    private function safe(callable $fn)
    {
        try { return $fn(); } catch (\Throwable $e) { return 0; }
    }
}
