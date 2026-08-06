<?php use App\Repositories\LeaveRequestRepository as LR; ?>
<div class="page-head"><div><h1>My Leave</h1><p class="subtle">Submit a leave request and track its status.</p></div></div>

<div class="card">
    <h2 class="panel-title">New leave request</h2>
    <form method="post" action="<?= url('me/leave/save') ?>" class="inline">
        <?= csrf_field() ?>
        <div class="field"><label>Type</label>
            <select name="leave_type">
                <?php foreach ($types as $t): ?><option><?= e($t) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field"><label>From</label><input type="date" name="from_date" required></div>
        <div class="field"><label>To</label><input type="date" name="to_date" required></div>
        <div class="field"><label>Contact while away</label><input name="contact" style="width:150px"></div>
        <div class="field" style="min-width:240px"><label>Reason</label><input name="reason"></div>
        <button type="submit">Submit request</button>
    </form>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Type</th><th>From</th><th>To</th><th class="num">Days</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): $st = (int) $r['StateID']; ?>
        <tr>
            <td><?= e($r['LeaveType']) ?></td>
            <td><?= date('d/m/Y', strtotime((string) $r['FromDate'])) ?></td>
            <td><?= date('d/m/Y', strtotime((string) $r['ToDate'])) ?></td>
            <td class="num"><?= (float) $r['Days'] ?></td>
            <td class="subtle"><?= e($r['Reason']) ?></td>
            <td><span class="chip <?= $st === LR::APPROVED ? 'present' : ($st === LR::PENDING ? 'pending' : 'day_off') ?>">
                <?= e(LR::STATE_LABELS[$st] ?? $st) ?></span></td>
            <td class="subtle"><?= e($r['DecisionNote']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$requests): ?><tr><td colspan="7" class="center subtle">No leave requests yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
