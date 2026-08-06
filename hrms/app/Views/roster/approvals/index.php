<?php
$statusChip = [
    'submitted'=>'pending','head_ok'=>'present','fa_ok'=>'present','mrd_ok'=>'present',
    'approved'=>'approved','rejected'=>'rejected',
];
?>
<div class="page-head"><div><h1>Approve Request</h1>
    <p class="subtle">Duty-roster submissions awaiting action. Chain: Dept Head → CNO (nurse) / COO·MD (doctor) → HR apply.</p></div>
    <form method="get" action="<?= url('approvals') ?>" class="inline">
        <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <button type="submit">Display</button>
    </form>
</div>

<div class="legend">
    <span class="leg"><span class="sw" style="background:var(--pending);border:1px solid #ccc"></span> Pending</span>
    <span class="leg"><span class="sw" style="background:#e8f6ee"></span> In progress</span>
    <span class="leg"><span class="sw day_off"></span> Approved</span>
    <span class="leg"><span class="sw no_punch"></span> Rejected</span>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>#</th><th>Period</th><th>Department</th><th>Section</th><th>Submitted By</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($subs as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= e(period_label($s['period_key'])) ?></td>
            <td><?= e($s['dept_name']) ?></td>
            <td><?= e($s['section_name']) ?></td>
            <td><?= e($s['submitted_name']) ?></td>
            <td class="subtle"><?= $s['submitted_at']?date('d M Y', strtotime($s['submitted_at'])):'' ?></td>
            <td><span class="chip <?= e($s['status_class'] ?? ($statusChip[$s['status']]??'pending')) ?>"><?= strtoupper(str_replace('_',' ',$s['status'])) ?></span></td>
            <td>
                <?php if ($s['can_act']): ?>
                <form method="post" action="<?= url('approvals/act') ?>" class="actions" style="gap:6px;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <input type="text" name="comments" placeholder="comment / reason…" style="max-width:150px">
                    <button class="btn btn-sm btn-ok" name="action" value="approve">Approve</button>
                    <button class="btn btn-sm btn-danger" name="action" value="reject" onclick="return confirm('Reject this submission?')">Reject</button>
                </form>
                <?php else: ?><span class="subtle">—</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$subs): ?><tr><td colspan="8" class="center subtle">No submissions in this date range.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>

<?php if (isset($corrections)): ?>
<h2 id="corrections" style="margin:22px 0 6px">Attendance Corrections</h2>
<p class="subtle" style="margin-top:0">Chain: Dept Head → HR. Once applied, the punch shows the rostered time in View Attendance.</p>
<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>#</th><th>Employee</th><th>Day</th><th>Correction</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($corrections as $c): ?>
        <tr>
            <td><?= $c['id'] ?></td>
            <td><?= e(trim($c['emp_code'].' '.$c['emp_name'])) ?></td>
            <td><?= $c['work_date'] ? date('d M', strtotime($c['work_date'])) : '' ?></td>
            <td><?= e($c['change']) ?></td>
            <td><?= e($c['reason']) ?></td>
            <td><span class="chip <?= e($c['status_class']) ?>"><?= strtoupper($c['status']) ?></span></td>
            <td>
                <?php if ($c['can_act']): ?>
                <form method="post" action="<?= url('approvals/correction') ?>" class="actions" style="gap:6px;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <input type="text" name="comments" placeholder="comment / reason…" style="max-width:140px">
                    <button class="btn btn-sm btn-ok" name="action" value="approve">Approve</button>
                    <button class="btn btn-sm btn-danger" name="action" value="reject" onclick="return confirm('Reject this correction?')">Reject</button>
                </form>
                <?php else: ?><span class="subtle">—</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$corrections): ?><tr><td colspan="7" class="center subtle">No pending corrections in this date range.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<?php if (isset($scheduleChanges)): ?>
<h2 id="schedule-changes" style="margin:22px 0 6px">Schedule Change Requests</h2>
<p class="subtle" style="margin-top:0">Chain: Dept Head (or CNO/COO·MD for clinical staff) → HR apply. Once applied, the employee's roster for that day updates to the new shift.</p>
<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>#</th><th>Employee</th><th>Day</th><th>Old Shift</th><th>New Shift</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($scheduleChanges as $sc): ?>
        <tr>
            <td><?= $sc['id'] ?></td>
            <td><?= e(trim($sc['emp_code'].' '.$sc['emp_name'])) ?></td>
            <td><?= $sc['work_date'] ? date('d M', strtotime($sc['work_date'])) : '' ?></td>
            <td><?= e($sc['old_code'] ?? '—') ?></td>
            <td><?= e($sc['new_code'] ?? '—') ?></td>
            <td><span class="chip <?= e($sc['status_class']) ?>"><?= strtoupper($sc['status']) ?></span></td>
            <td>
                <?php if ($sc['can_act']): ?>
                <form method="post" action="<?= url('approvals/schedule-change') ?>" class="actions" style="gap:6px;flex-wrap:wrap">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $sc['id'] ?>">
                    <input type="text" name="comments" placeholder="comment / reason…" style="max-width:140px">
                    <button class="btn btn-sm btn-ok" name="action" value="approve">Approve</button>
                    <button class="btn btn-sm btn-danger" name="action" value="reject" onclick="return confirm('Reject this schedule change?')">Reject</button>
                </form>
                <?php else: ?><span class="subtle">—</span><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$scheduleChanges): ?><tr><td colspan="7" class="center subtle">No pending schedule changes in this date range.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
