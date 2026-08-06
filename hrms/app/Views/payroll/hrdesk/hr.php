<?php use App\Payroll\Repositories\HrRequestRepository as HR; ?>
<div class="page-head"><div><h1>HR Requests</h1><p class="subtle">Open requests from staff — certificates, letters, queries.</p></div></div>
<div class="card" style="padding:10px 14px"><div class="actions">
    <a class="btn-ghost btn-sm" href="<?= url('hr/leave') ?>">Leave requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/requests') ?>">HR requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/cme') ?>">CME compliance</a>
</div></div>

<?php foreach ($queue as $r): $st = (int) $r['StateID']; ?>
<div class="card">
    <div class="page-head" style="margin-bottom:10px">
        <div><strong><?= e($r['Subject']) ?></strong>
            <span class="chip pending"><?= e($r['Category']) ?></span>
            <span class="chip <?= $st >= HR::RESOLVED ? 'present' : 'pending' ?>"><?= e(HR::STATE_LABELS[$st] ?? '') ?></span>
            <div class="subtle"><?= e($r['emp_code'] . ' · ' . $r['emp_name']) ?> · <?= date('d/m/Y', strtotime((string) $r['CreatedAt'])) ?></div>
        </div>
    </div>
    <?php if ($r['Message']): ?><p><?= nl2br(e($r['Message'])) ?></p><?php endif; ?>
    <form method="post" action="<?= url('hr/requests/respond') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="request_id" value="<?= (int) $r['RequestID'] ?>">
        <div class="field"><label>Response</label><textarea name="response" rows="2"><?= e($r['Response']) ?></textarea></div>
        <div class="actions">
            <button class="btn-muted btn-sm" name="state" value="<?= HR::IN_PROGRESS ?>">Mark in progress</button>
            <button class="btn-ok btn-sm" name="state" value="<?= HR::RESOLVED ?>">Resolve</button>
            <button class="btn-muted btn-sm" name="state" value="<?= HR::CLOSED ?>">Close</button>
        </div>
    </form>
</div>
<?php endforeach; ?>
<?php if (!$queue): ?><div class="card subtle">No open HR requests.</div><?php endif; ?>
