<?php
namespace App\Payroll\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Payroll\Repositories\PayrollRepository;
use App\Payroll\Repositories\LeaveRequestRepository;
use App\Payroll\Repositories\LeaveBalanceRepository;
use App\Payroll\Repositories\HrRequestRepository;
use App\Payroll\Repositories\CmeRepository;
use App\Payroll\Services\AttachmentService;

/**
 * Employee self-service — everything a member of staff can do for themselves:
 * see and print their payslips, submit and track leave, raise requests to HR,
 * and log their training (CME) hours against what is required.
 *
 * Every screen is scoped to the signed-in user's own employee record; there is
 * no way to see anyone else's data here.
 */
class EssController extends Controller
{
    public function home(): void
    {
        Auth::require();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }

        $year = (int) date('Y');
        $cme  = new CmeRepository($this->db);
        $done = $cme->completedHours($empId, $year);

        $this->view('ess/home', [
            'title'      => 'My Self-Service',
            'payslips'   => (new PayrollRepository($this->db))->payslipMonths($empId, 3),
            'leave'      => array_slice((new LeaveRequestRepository($this->db))->forEmployee($empId), 0, 3),
            'hr'         => array_slice((new HrRequestRepository($this->db))->forEmployee($empId), 0, 3),
            'cmeRequired'=> $cme->requiredHours($empId, $year),
            'cmeDone'    => $done['recorded'],
            'year'       => $year,
        ], 'app');
    }

    // ------------------------------------------------------------ payslips --

    public function payslips(): void
    {
        Auth::require();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }
        $this->view('ess/payslips', [
            'title'  => 'My Payslips',
            'months' => (new PayrollRepository($this->db))->payslipMonths($empId),
        ]);
    }

    // -------------------------------------------------------------- leave --

    public function leave(): void
    {
        Auth::require();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }
        $this->view('ess/leave', [
            'title'        => 'My Leave',
            'requests'     => (new LeaveRequestRepository($this->db))->forEmployee($empId),
            'types'        => (array) Config::get('payroll.leave_types', ['Annual', 'Sick', 'Unpaid']),
            'balances'     => (new LeaveBalanceRepository($this->db))->summary($empId),
            'attachTypes'  => (array) Config::get('payroll.leave_attachment_types', ['Sick']),
            'balanceTypes' => (array) Config::get('payroll.leave_balance_types', ['Annual']),
            'allowedExt'   => (array) Config::get('payroll.uploads.allowed_ext', ['pdf', 'jpg', 'png']),
            'stateLabels'  => LeaveRequestRepository::STATE_LABELS,
        ]);
    }

    public function leaveSave(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }

        $from = $this->input('from_date');
        $to   = $this->input('to_date');
        $type = $this->input('leave_type', 'Annual');
        if (!$from || !$to || $to < $from) {
            $this->flash('error', 'Enter a valid from and to date.');
            $this->redirect('me/leave');
        }
        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        $year = (int) date('Y', strtotime($from));
        $bal  = new LeaveBalanceRepository($this->db);

        // Reserve the days against the balance first (checks availability).
        if ($bal->tracked($type) && !$bal->reserve($empId, $type, (float) $days, $year)) {
            $avail = $bal->available($empId, $type, $year);
            $this->flash('error',
                "Not enough {$type} leave balance: {$days} day(s) requested, {$avail} available.");
            $this->redirect('me/leave');
        }

        // Optional supporting document (prompted for Sick, allowed for any type).
        // Desktop uses the "attachment" field; a phone camera posts "attachment_cam".
        $att = ['name' => null, 'path' => null, 'ocr' => null];
        $file = !empty($_FILES['attachment']['name'] ?? '')     ? $_FILES['attachment']
              : (!empty($_FILES['attachment_cam']['name'] ?? '') ? $_FILES['attachment_cam'] : null);
        if ($file) {
            $res = AttachmentService::store($file, 'leave');
            if (is_array($res) && isset($res['error'])) {
                if ($bal->tracked($type)) $bal->release($empId, $type, (float) $days, $year);
                $this->flash('error', $res['error']);
                $this->redirect('me/leave');
            }
            if (is_array($res)) $att = $res;
        }

        (new LeaveRequestRepository($this->db))->create([
            'employee_id'     => $empId,
            'leave_type'      => $type,
            'from'            => $from,
            'to'              => $to,
            'days'            => $days,
            'reason'          => $this->input('reason') ?: null,
            'contact'         => $this->input('contact') ?: null,
            'attachment_name' => $att['name'],
            'attachment_path' => $att['path'],
            'attachment_ocr'  => $att['ocr'],
        ]);

        $msg = "Leave request submitted for {$days} day(s). HR will review it.";
        if ($att['name']) {
            $msg .= $att['ocr'] ? ' Document attached and scanned.' : ' Document attached.';
        }
        $this->flash('success', $msg);
        $this->redirect('me/leave');
    }

    /** Stream a leave attachment to its owner or to HR. */
    public function leaveAttachment(): void
    {
        Auth::require();
        $empId = $this->empId();
        $req   = (new LeaveRequestRepository($this->db))->find((int) $this->input('id'));
        $isHr  = Auth::atLeast((string) Config::get('payroll.roles.process', 'fa'));
        if (!$req || (!$isHr && (int) $req['EmployeeID'] !== $empId)) {
            http_response_code(404); echo 'Attachment not found.'; return;
        }
        $abs = $req['AttachmentPath'] ? AttachmentService::absolutePath((string) $req['AttachmentPath']) : null;
        if (!$abs) { http_response_code(404); echo 'The file is no longer available.'; return; }
        AttachmentService::stream($abs, (string) ($req['AttachmentName'] ?: 'attachment'));
    }

    // ------------------------------------------------------- requests to HR --

    public function hr(): void
    {
        Auth::require();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }
        $this->view('ess/hr', [
            'title'      => 'Requests to HR',
            'requests'   => (new HrRequestRepository($this->db))->forEmployee($empId),
            'categories' => (array) Config::get('payroll.hr_request_categories', ['Other']),
        ]);
    }

    public function hrSave(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }

        if (!$this->input('subject')) {
            $this->flash('error', 'A subject is required.');
            $this->redirect('me/hr');
        }
        (new HrRequestRepository($this->db))->create([
            'employee_id' => $empId,
            'category'    => $this->input('category', 'Other'),
            'subject'     => $this->input('subject'),
            'message'     => $this->input('message') ?: null,
        ]);
        $this->flash('success', 'Request sent to HR.');
        $this->redirect('me/hr');
    }

    // ---------------------------------------------------------------- CME --

    public function cme(): void
    {
        Auth::require();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }
        $year = (int) $this->input('year', date('Y'));
        $cme  = new CmeRepository($this->db);
        $done = $cme->completedHours($empId, $year);
        $required = $cme->requiredHours($empId, $year);

        $this->view('ess/cme', [
            'title'      => 'My Training (CME)',
            'year'       => $year,
            'required'   => $required,
            'verified'   => $done['verified'],
            'recorded'   => $done['recorded'],
            'pct'        => $required > 0 ? min(100, round($done['recorded'] / $required * 100)) : 0,
            'activities' => $cme->activities($empId, $year),
        ]);
    }

    public function cmeSave(): void
    {
        Auth::require();
        $this->verifyCsrf();
        $empId = $this->empId();
        if (!$empId) { $this->notLinked(); return; }

        $year  = (int) $this->input('year', date('Y'));
        $hours = (float) $this->input('hours', 0);
        if (!$this->input('title') || $hours <= 0) {
            $this->flash('error', 'A title and a positive number of hours are required.');
            $this->redirect('me/cme?year=' . $year);
        }
        (new CmeRepository($this->db))->addActivity([
            'employee_id'   => $empId,
            'year'          => $year,
            'title'         => $this->input('title'),
            'provider'      => $this->input('provider') ?: null,
            'hours'         => $hours,
            'activity_date' => $this->input('activity_date') ?: null,
        ]);
        $this->flash('success', 'Training activity logged. It shows as Recorded until HR verifies it.');
        $this->redirect('me/cme?year=' . $year);
    }

    // ------------------------------------------------------------- helpers --

    private function empId(): int
    {
        return (int) (Auth::user()['employee_id'] ?? 0);
    }

    private function notLinked(): void
    {
        $this->view('ess/not_linked', ['title' => 'Self-Service']);
    }
}
