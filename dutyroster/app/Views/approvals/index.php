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
