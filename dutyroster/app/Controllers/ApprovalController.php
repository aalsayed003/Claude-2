<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;

/**
 * Multi-level approval chain for roster submissions:
 *   submitted -> head_ok -> fa_ok -> mrd_ok -> approved
 * Any approver at/above the required level may reject.
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

        // Which submissions can the current user act on next?
        foreach ($subs as &$s) {
            $step = self::CHAIN[$s['status']] ?? null;
            $s['can_act'] = $step && Auth::atLeast($step['role']);
        }
        unset($s);

        $this->view('approvals/index', [
            'title' => 'Approve Request',
            'subs'  => $subs,
            'from'  => $from,
            'to'    => $to,
        ]);
    }

    public function act(): void
    {
        Auth::requireRole('dept_head');
        $this->verifyCsrf();
        $id      = (int) $this->input('id');
        $action  = $this->input('action'); // approve | reject
        $comment = $this->input('comments') ?: null;

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
}
