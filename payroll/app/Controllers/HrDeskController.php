<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Repositories\LeaveRequestRepository;
use App\Repositories\HrRequestRepository;
use App\Repositories\CmeRepository;

/**
 * HR desk — the staff side of self-service: approve/reject leave, respond to HR
 * requests, and oversee training (CME) compliance. Gated to HR / Finance.
 */
class HrDeskController extends Controller
{
    // ----------------------------------------------------- leave requests --

    public function leave(): void
    {
        $this->requireRole('process');
        $this->view('hrdesk/leave', [
            'title'    => 'Leave Requests',
            'pending'  => (new LeaveRequestRepository($this->db))->pending(),
        ]);
    }

    public function leaveDecide(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $repo = new LeaveRequestRepository($this->db);
        $req  = $repo->find((int) $this->input('request_id'));
        if (!$req) { $this->flash('error', 'Request not found.'); $this->redirect('hr/leave'); }

        $state = $this->input('decision') === 'approve'
            ? LeaveRequestRepository::APPROVED : LeaveRequestRepository::REJECTED;
        $repo->decide((int) $req['RequestID'], $state, $this->user(), $this->input('note') ?: null);
        $this->flash('success', 'Leave request ' . ($state === LeaveRequestRepository::APPROVED ? 'approved' : 'rejected') . '.');
        $this->redirect('hr/leave');
    }

    // ---------------------------------------------------------- HR queue --

    public function requests(): void
    {
        $this->requireRole('process');
        $this->view('hrdesk/hr', [
            'title' => 'HR Requests',
            'queue' => (new HrRequestRepository($this->db))->queue(),
        ]);
    }

    public function requestRespond(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $repo = new HrRequestRepository($this->db);
        $req  = $repo->find((int) $this->input('request_id'));
        if (!$req) { $this->flash('error', 'Request not found.'); $this->redirect('hr/requests'); }

        $state = (int) $this->input('state', HrRequestRepository::RESOLVED);
        $repo->respond((int) $req['RequestID'], $state, $this->input('response') ?: null, $this->user());
        $this->flash('success', 'Request updated.');
        $this->redirect('hr/requests');
    }

    // --------------------------------------------------------------- CME --

    public function cme(): void
    {
        $this->requireRole('process');
        $year   = (int) $this->input('year', date('Y'));
        $deptId = $this->input('department_id') ? (int) $this->input('department_id') : null;
        $cme    = new CmeRepository($this->db);

        $this->view('hrdesk/cme', [
            'title'       => 'Training (CME) Compliance',
            'year'        => $year,
            'rows'        => $cme->overview($year, $deptId),
            'pending'     => $cme->pendingActivities($year),
            'deptId'      => $deptId,
            'departments' => $this->db->all(
                "SELECT Id AS id, Name AS name FROM " . lt('department') . " WHERE Deleted = 0 ORDER BY Name"),
            'defaultReq'  => (float) Config::get('payroll.cme.required_hours_per_year', 50),
        ]);
    }

    /** CME requirement master — required hours by staff category. */
    public function cmeCategories(): void
    {
        $this->requireRole('process');
        $year = (int) $this->input('year', date('Y'));
        $this->view('hrdesk/cme_categories', [
            'title'      => 'CME Requirement Master',
            'year'       => $year,
            'categories' => (new CmeRepository($this->db))->categories($year),
        ]);
    }

    public function cmeCategorySave(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $catId = (int) $this->input('category_id');
        $year  = (int) $this->input('year', date('Y'));
        $hours = (float) $this->input('hours', 0);
        $name  = $this->input('name') ?: null;
        if ($catId && $hours >= 0) {
            (new CmeRepository($this->db))->setCategoryRequired($catId, $name, $year, $hours, $this->user());
            $this->flash('success', 'Category requirement saved.');
        }
        $this->redirect('hr/cme/categories?year=' . $year);
    }

    public function cmeRequire(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $empId = (int) $this->input('employee_id');
        $year  = (int) $this->input('year', date('Y'));
        $hours = (float) $this->input('hours', 0);
        if ($empId && $hours >= 0) {
            (new CmeRepository($this->db))->setRequired($empId, $year, $hours, $this->user());
            $this->flash('success', 'Required hours updated.');
        }
        $this->redirect('hr/cme?year=' . $year);
    }

    public function cmeVerify(): void
    {
        $this->requireRole('process');
        $this->verifyCsrf();
        $cme = new CmeRepository($this->db);
        $act = $cme->findActivity((int) $this->input('activity_id'));
        if ($act) {
            $state = $this->input('decision') === 'reject' ? CmeRepository::REJECTED : CmeRepository::VERIFIED;
            $cme->setState((int) $act['ActivityID'], $state);
            $this->flash('success', 'Activity ' . ($state === CmeRepository::VERIFIED ? 'verified' : 'rejected') . '.');
        }
        $this->redirect('hr/cme?year=' . (int) $this->input('year', date('Y')));
    }

    // ------------------------------------------------------------- helpers --

    private function requireRole(string $action): void
    {
        Auth::requireRole((string) Config::get('payroll.roles.' . $action, 'fa'));
    }

    private function user(): string
    {
        return substr((string) (Auth::user()['username'] ?? 'system'), 0, 20);
    }
}
