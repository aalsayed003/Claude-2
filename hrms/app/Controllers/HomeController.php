<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

/**
 * Combined HRMS landing: duty-roster "today" figures + payroll month status in
 * one view. Each block is guarded so a data gap in one module can't break the
 * page. Roster drill-downs reuse the roster dashboard's detail route.
 */
class HomeController extends Controller
{
    public function index(): void
    {
        Auth::require();
        $today  = date('Y-m-d');
        $period = period_of($today);
        [$start, $end] = period_bounds($period);
        $safe = fn(callable $f, $d = 0) => (function () use ($f, $d) { try { return $f(); } catch (\Throwable $e) { return $d; } })();

        // ---- Duty Roster (today) ----
        $roster = ['late' => 0, 'early' => 0, 'absent' => 0, 'odd_punch' => 0, 'day_off' => 0];
        $counts = $safe(fn() => \App\Roster\Services\DashboardMetrics::todayCounts($this->db, $today), []);
        foreach (['absent', 'odd_punch', 'late', 'early', 'day_off'] as $k) $roster[$k] = (int) ($counts[$k] ?? 0);
        $pendingSchedules   = $safe(fn() => (new \App\Roster\Repositories\ScheduleRequestRepository($this->db))->pendingCount());
        $pendingCorrections = $safe(fn() => (new \App\Roster\Repositories\CorrectionRepository($this->db))->pendingCount($start, $end));

        // ---- Payroll (current cutoff month) ----
        $payMonth = payroll_month_of_period($period);   // 'YYYY-MM-01'
        $run = $safe(fn() => (new \App\Payroll\Repositories\PayrollRepository($this->db))->findRunByMonth($payMonth), null);
        $stateNames = [1 => 'Draft', 2 => 'Calculated', 3 => 'Approved', 4 => 'Locked', 9 => 'Cancelled'];

        $this->view('home/index', [
            'title'   => 'Dashboard',
            'today'   => $today,
            'period'  => $period,
            'roster'  => $roster,
            'pendingSchedules'   => $pendingSchedules,
            'pendingCorrections' => $pendingCorrections,
            'payMonth' => $payMonth,
            'run'      => $run,
            'runState' => $run ? ($stateNames[(int) ($run['StateID'] ?? 1)] ?? 'Draft') : null,
        ]);
    }
}
