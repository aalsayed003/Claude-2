<?php
namespace App\Roster\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Roster\Services\ApprovalFlow;
use App\Roster\Services\CorrectionFlow;
use App\Roster\Services\ScheduleChangeFlow;
use App\Roster\Services\RequestCategory;
use App\Roster\Repositories\ScheduleRequestRepository;
use App\Roster\Repositories\CorrectionRepository;
use App\Roster\Repositories\ScheduleChangeRepository;

/**
 * Duty-roster submission approvals.
 *
 * Legacy mode drives Schedule_Request through the category chain
 * (Dept Head -> CNO/COO-MD -> HR apply); the clean schema keeps the
 * submitted -> head_ok -> fa_ok -> mrd_ok -> approved chain below.
 */
class ApprovalController extends Controller
{
    private const CHAIN = [
        'submitted' => ['role' => 'dept_head', 'next' => 'head_ok', 'stamp' => 'head'],
        'head_ok'   => ['role' => 'fa',        'next' => 'fa_ok',   'stamp' => 'fa'],
        'fa_ok'     => ['role' => 'mrd',       'next' => 'mrd_ok',  'stamp' => 'mrd'],
        'mrd_ok'    => ['role' => 'coo',       'next' => 'approved','stamp' => 'coo'],
    ];

    public function index(): void
    {
        Auth::requireRole('dept_head');
        $from = $this->input('from', date('Y-m-01', strtotime('-1 month')));
        $to   = $this->input('to', date('Y-m-d'));

        if (legacy_mode()) {
            $subs = (new ScheduleRequestRepository($this->db))->list($from, $to);
            foreach ($subs as &$s) {
                $cat  = $this->categoryForDept((int) $s['department_id']);
                $step = ApprovalFlow::step($cat, (int) $s['approved'], (int) $s['uploaded']);
                $s['status']       = ApprovalFlow::statusLabel($cat, (int) $s['approved'], (int) $s['uploaded']);
                $s['status_class'] = ApprovalFlow::statusClass($cat, (int) $s['approved'], (int) $s['uploaded']);
                $s['next_role']    = $step['label'] ?? null;
                $s['can_act']      = $step !== null && $this->canAct($step['role']);
            }
            unset($s);
        } else {
            $subs = $this->db->all(
                "SELECT rs.*, d.name AS dept_name, sec.name AS section_name,
                        u.full_name AS submitted_name
                   FROM roster_submissions rs
                   LEFT JOIN departments d ON d.id = rs.department_id
                   LEFT JOIN sections sec ON sec.id = rs.section_id
                   LEFT JOIN users u ON u.id = rs.submitted_by
                  WHERE rs.submitted_at BETWEEN :a AND :b
                  ORDER BY rs.submitted_at DESC",
                [':a' => $from . ' 00:00:00', ':b' => $to . ' 23:59:59']
            );
            foreach ($subs as &$s) {
                $step = self::CHAIN[$s['status']] ?? null;
                $s['can_act'] = $step && Auth::atLeast($step['role']);
            }
            unset($s);
        }

        // Attendance corrections awaiting approval (legacy only).
        $corrections = [];
        if (legacy_mode()) {
            $corrections = (new CorrectionRepository($this->db))->pendingForApproval($from, $to);
            foreach ($corrections as &$c) {
                $cat  = RequestCategory::forDept((int) ($c['department_id'] ?? 0));
                $step = CorrectionFlow::step((int) $c['state_id'], $cat);
                $c['status']       = CorrectionFlow::statusLabel((int) $c['state_id'], $cat);
                $c['status_class'] = CorrectionFlow::statusClass((int) $c['state_id']);
                $c['can_act']      = $step !== null && $this->canAct($step['role']);
            }
            unset($c);
        }

        // Schedule-change requests awaiting approval (legacy only).
        $scheduleChanges = [];
        if (legacy_mode()) {
            $scheduleChanges = (new ScheduleChangeRepository($this->db))->pendingForApproval($from, $to);
            foreach ($scheduleChanges as &$sc) {
                $cat  = RequestCategory::forDept((int) ($sc['department_id'] ?? 0));
                $step = ScheduleChangeFlow::step((int) $sc['state_id'], $cat);
                $sc['status']       = ScheduleChangeFlow::statusLabel((int) $sc['state_id'], $cat);
                $sc['status_class'] = ScheduleChangeFlow::statusClass((int) $sc['state_id']);
                $sc['can_act']      = $step !== null && $this->canAct($step['role']);
            }
            unset($sc);
        }

        $this->view('approvals/index', [
            'title'           => 'Approve Request',
            'subs'            => $subs,
            'corrections'     => $corrections,
            'scheduleChanges' => $scheduleChanges,
            'from'            => $from,
            'to'              => $to,
        ]);
    }

    public function act(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $id      = (int) $this->input('id');
        $action  = $this->input('action'); // approve | reject
        $comment = $this->input('comments') ?: null;

        if (legacy_mode()) {
            $this->actLegacy($id, $action, $comment);
            return;
        }

        $sub = $this->db->one("SELECT * FROM roster_submissions WHERE id = :id", [':id' => $id]);
        if (!$sub) {
            $this->flash('error', 'Submission not found.');
            $this->redirect('approvals');
        }

        if ($action === 'reject') {
            $this->db->update('roster_submissions', [
                'status'      => 'rejected',
                'rejected_by' => Auth::id(),
                'rejected_at' => date('Y-m-d H:i:s'),
                'comments'    => $comment,
            ], 'id = :id', [':id' => $id]);
            $this->flash('success', 'Submission rejected.');
            $this->redirect('approvals');
        }

        $step = self::CHAIN[$sub['status']] ?? null;
        if (!$step) {
            $this->flash('error', 'This submission is already fully processed.');
            $this->redirect('approvals');
        }
        if (!Auth::atLeast($step['role'])) {
            $this->flash('error', 'You are not authorised for this approval step.');
            $this->redirect('approvals');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->update('roster_submissions', [
            'status'                 => $step['next'],
            $step['stamp'] . '_by'   => Auth::id(),
            $step['stamp'] . '_at'   => $now,
            'comments'               => $comment ?? $sub['comments'],
        ], 'id = :id', [':id' => $id]);

        $this->flash('success', 'Approved — moved to "' . $step['next'] . '".');
        $this->redirect('approvals');
    }

    /** Approve / reject a legacy Schedule_Request, then flash + redirect. */
    private function actLegacy(int $id, string $action, ?string $comment): void
    {
        $res = $this->legacyDecision($id, $action, $comment);
        $this->flash($res['ok'] ? 'success' : 'error', $res['msg']);
        $this->redirect('approvals');
    }

    /**
     * The testable core: advance/reject a Schedule_Request along the category
     * chain and record the audit. Returns ['ok'=>bool,'msg'=>string] without
     * redirecting.
     */
    private function legacyDecision(int $id, string $action, ?string $comment): array
    {
        $t   = lt('sched_req');
        $req = (new ScheduleRequestRepository($this->db))->find($id);
        if (!$req) {
            return ['ok' => false, 'msg' => 'Submission not found.'];
        }

        $cat      = $this->categoryForDept((int) ($req['DepartmentId'] ?? 0));
        $approved = (int) ($req['Approved'] ?? 0);
        $uploaded = (int) ($req['Uploaded'] ?? 0);
        $step     = ApprovalFlow::step($cat, $approved, $uploaded);

        if (!$step) {
            return ['ok' => false, 'msg' => 'This submission is already fully processed.'];
        }
        if (!$this->canAct($step['role'])) {
            return ['ok' => false, 'msg' => 'You are not authorised for this step (' . $step['label'] . ').'];
        }

        if ($action === 'reject') {
            $this->db->update($t, [
                'Approved' => ApprovalFlow::rejectCode(),
                'Reason'   => $comment,
            ], 'ID = :id', [':id' => $id]);
            $this->logAction($id, -1, 'Rejected at ' . $step['label'] . ($comment ? ': ' . $comment : ''));
            return ['ok' => true, 'msg' => 'Submission rejected.'];
        }

        $set = $step['set'];
        if ($comment) {
            $set['Comments'] = $comment;
        }
        $this->db->update($t, $set, 'ID = :id', [':id' => $id]);
        $this->logAction($id, (int) ($set['Approved'] ?? $approved),
            ($step['is_apply'] ? 'Applied by ' : 'Approved by ') . $step['label'] . ($comment ? ': ' . $comment : ''));

        $next = ApprovalFlow::step($cat,
            (int) ($set['Approved'] ?? $approved),
            (int) ($set['Uploaded'] ?? $uploaded));
        return ['ok' => true, 'msg' => $step['is_apply']
            ? 'Applied to the live roster.'
            : 'Approved — now awaiting ' . ($next['label'] ?? 'HR') . '.'];
    }

    /** Approve / reject an attendance correction (Dept Head -> HR), then redirect. */
    public function actCorrection(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $res = $this->correctionDecision(
            (int) $this->input('id'),
            $this->input('action'),
            $this->input('comments') ?: null
        );
        $this->flash($res['ok'] ? 'success' : 'error', $res['msg']);
        $this->redirect('approvals');
    }

    /** Testable core: advance/reject a correction. Returns ['ok'=>,'msg'=>]. */
    private function correctionDecision(int $id, string $action, ?string $comment): array
    {
        $t = lt('correction_table') ?: 'DR_CorrectionRequest';
        $c = (new CorrectionRepository($this->db))->find($id);
        if (!$c) {
            return ['ok' => false, 'msg' => 'Correction not found.'];
        }

        $state = (int) ($c['StateID'] ?? 0);
        $cat   = RequestCategory::forDept((int) ($c['DepartmentId'] ?? 0));
        $step  = CorrectionFlow::step($state, $cat);
        if (!$step) {
            return ['ok' => false, 'msg' => 'This correction is already fully processed.'];
        }
        if (!$this->canAct($step['role'])) {
            return ['ok' => false, 'msg' => 'You are not authorised for this step (' . $step['label'] . ').'];
        }

        if ($action === 'reject') {
            $set = ['StateID' => CorrectionFlow::rejectState()];
            if ($comment) {
                $set['Remarks'] = mb_substr(trim(($c['Remarks'] ? $c['Remarks'] . ' | ' : '') . 'Rejected: ' . $comment), 0, 250);
            }
            $this->db->update($t, $set, 'RequestID = :id', [':id' => $id]);
            return ['ok' => true, 'msg' => 'Correction rejected.'];
        }

        $this->db->update($t, ['StateID' => $step['to_state']], 'RequestID = :id', [':id' => $id]);
        return ['ok' => true, 'msg' => $step['is_apply']
            ? 'Correction applied — the punch will now show the rostered time in View Attendance.'
            : 'Approved — now awaiting HR.'];
    }

    /** Approve / reject a schedule-change request (Dept Head/CNO/COO-MD -> HR), then redirect. */
    public function actScheduleChange(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $res = $this->scheduleChangeDecision(
            (int) $this->input('id'),
            $this->input('action'),
            $this->input('comments') ?: null
        );
        $this->flash($res['ok'] ? 'success' : 'error', $res['msg']);
        $this->redirect('approvals');
    }

    /** Testable core: advance/reject a schedule-change request. Returns ['ok'=>,'msg'=>]. */
    private function scheduleChangeDecision(int $id, string $action, ?string $comment): array
    {
        $t  = lt('change_sched');
        $sc = (new ScheduleChangeRepository($this->db))->find($id);
        if (!$sc) {
            return ['ok' => false, 'msg' => 'Schedule change request not found.'];
        }

        $state = (int) ($sc['StateID'] ?? 0);
        $cat   = RequestCategory::forDept((int) ($sc['DepartmentId'] ?? 0));
        $step  = ScheduleChangeFlow::step($state, $cat);
        if (!$step) {
            return ['ok' => false, 'msg' => 'This request is already fully processed.'];
        }
        if (!$this->canAct($step['role'])) {
            return ['ok' => false, 'msg' => 'You are not authorised for this step (' . $step['label'] . ').'];
        }

        if ($action === 'reject') {
            $set = ['StateID' => ScheduleChangeFlow::rejectState()];
            if ($comment) {
                $set['RejectReason'] = mb_substr(trim((string) $comment), 0, 250);
            }
            $this->db->update($t, $set, 'RequestID = :id', [':id' => $id]);
            return ['ok' => true, 'msg' => 'Schedule change request rejected.'];
        }

        // Apply the roster change FIRST, then flip the state — both in one
        // transaction — so we never end up "Applied" with the roster unchanged,
        // and any failure surfaces as a message instead of a 500.
        try {
            $this->db->begin();
            if ($step['is_apply']) {
                $newShiftId = (int) ($sc['ChangeShiftID'] ?? 0);
                $work = $this->scheduleChangeWorkDate($sc);
                if ($newShiftId > 0 && $work !== null) {
                    (new \App\Roster\Repositories\RosterRepository($this->db))
                        ->applyShiftChange((int) $sc['EmployeeID'], $work, $newShiftId);
                }
            }
            $this->db->update($t, ['StateID' => $step['to_state']], 'RequestID = :id', [':id' => $id]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ['ok' => false, 'msg' => 'Could not apply the schedule change: ' . $e->getMessage()];
        }

        return ['ok' => true, 'msg' => $step['is_apply']
            ? 'Applied — the employee\'s roster for that day is now updated.'
            : 'Approved — now awaiting HR.'];
    }

    /** ScheduleMonth + ScheduleDay -> 'Y-m-d', as stored on DR_ChangeSchedule. */
    private function scheduleChangeWorkDate(array $sc): ?string
    {
        if (empty($sc['ScheduleMonth'])) return null;
        $ym  = date('Y-m', strtotime((string) $sc['ScheduleMonth']));
        $day = str_pad((string) (int) ($sc['ScheduleDay'] ?? 1), 2, '0', STR_PAD_LEFT);
        return "{$ym}-{$day}";
    }

    /** Department -> approval category (nurse / doctor / default). Until payroll
     *  supplies employee category, a config map (legacy.dept_category) drives it;
     *  unmapped departments use the plain Dept Head -> HR chain. */
    private function categoryForDept(int $deptId): string
    {
        return RequestCategory::forDept($deptId);
    }

    /** Admin may action any gate; otherwise the user's role must match the gate. */
    private function canAct(string $role): bool
    {
        return Auth::isAdmin() || Auth::role() === $role;
    }

    /** Best-effort audit into Schedule_RequestActions. */
    private function logAction(int $reqId, int $actionId, string $comment): void
    {
        try {
            $this->db->insert(lt('sched_act'), [
                'RequestID'  => $reqId,
                'ActionDate' => date('Y-m-d H:i:s'),
                'Comments'   => mb_substr($comment, 0, 500),
                'UserID'     => (string) (Auth::id() ?? ''),
                'ActionID'   => $actionId,
            ]);
        } catch (\Throwable $e) {
            // audit table absent or shaped differently — approval already recorded
        }
    }
}
