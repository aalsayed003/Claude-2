<div class="page-head"><div><h1>Leave Requests</h1><p class="subtle">Pending leave submitted by staff.</p></div></div>
<div class="card" style="padding:10px 14px"><div class="actions">
    <a class="btn-ghost btn-sm" href="<?= url('hr/leave') ?>">Leave requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/requests') ?>">HR requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/cme') ?>">CME compliance</a>
</div></div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Submitted</th><th>Employee</th><th>Department</th><th>Type</th><th>From</th><th>To</th>
        <th class="num">Days</th><th>Reason</th><th style="min-width:260px">Decision</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $r): ?>
        <tr>
            <td class="subtle"><?= date('d/m/Y', strtotime((string) $r['CreatedAt'])) ?></td>
            <td><?= e($r['emp_code'] . ' · ' . $r['emp_name']) ?></td>
            <td class="subtle"><?= e($r['dept_name']) ?></td>
            <td><?= e($r['LeaveType']) ?></td>
            <td><?= date('d/m/Y', strtotime((string) $r['FromDate'])) ?></td>
            <td><?= date('d/m/Y', strtotime((string) $r['ToDate'])) ?></td>
            <td class="num"><?= (float) $r['Days'] ?></td>
            <td class="subtle"><?= e($r['Reason']) ?></td>
            <td>
                <form method="post" action="<?= url('hr/leave/decide') ?>" class="inline" style="gap:6px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $r['RequestID'] ?>">
                    <input name="note" placeholder="note" style="width:110px">
                    <button class="btn-ok btn-sm" name="decision" value="approve">Approve</button>
                    <button class="btn-muted btn-sm" name="decision" value="reject">Reject</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$pending): ?><tr><td colspan="9" class="center subtle">No pending leave requests.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
