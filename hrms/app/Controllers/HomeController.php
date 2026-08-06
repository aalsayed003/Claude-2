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
        // Pending queues are counted over a wide window so nothing awaiting a
        // decision is missed just because it was raised in an earlier period.
        $wFrom = '2000-01-01';
        $wTo   = date('Y-m-d', strtotime('+1 day'));
        $pendingSchedules       = $safe(fn() => (new \App\Roster\Repositories\ScheduleRequestRepository($this->db))->pendingCount());
        $pendingScheduleChanges = $safe(fn() => (new \App\Roster\Repositories\ScheduleChangeRepository($this->db))->pendingCount($wFrom, $wTo));
        $pendingCorrections     = $safe(fn() => (new \App\Roster\Repositories\CorrectionRepository($this->db))->pendingCount($wFrom, $wTo));
        $salaryCertCategory     = (string) \App\Core\Config::get('payroll.salary_certificate_category', 'Salary certificate');
        $pendingSalaryCerts     = $safe(fn() => (new \App\Payroll\Repositories\HrRequestRepository($this->db))->openCount($salaryCertCategory));
        $pendingLeave           = $safe(fn() => (new \App\Payroll\Repositories\LeaveRequestRepository($this->db))->pendingCount());

        // ---- Payroll (current cutoff month) ----
        $payMonth = payroll_month_of_period($period);   // 'YYYY-MM-01'
        $run = $safe(fn() => (new \App\Payroll\Repositories\PayrollRepository($this->db))->findRunByMonth($payMonth), null);
        $stateNames = [1 => 'Draft', 2 => 'Calculated', 3 => 'Approved', 4 => 'Locked', 9 => 'Cancelled'];

        $this->view('home/index', [
            'title'   => 'Dashboard',
            'today'   => $today,
            'period'  => $period,
            'roster'  => $roster,
            'pendingSchedules'       => $pendingSchedules,
            'pendingScheduleChanges' => $pendingScheduleChanges,
            'pendingCorrections'     => $pendingCorrections,
            'pendingSalaryCerts'     => $pendingSalaryCerts,
            'pendingLeave'           => $pendingLeave,
            'salaryCertCategory'     => $salaryCertCategory,
            'payMonth' => $payMonth,
            'run'      => $run,
            'runState' => $run ? ($stateNames[(int) ($run['StateID'] ?? 1)] ?? 'Draft') : null,
        ]);
    }
}
