<?php use App\Repositories\HrRequestRepository as HR; ?>
<div class="page-head"><div><h1>Requests to HR</h1><p class="subtle">Salary certificates, letters and queries.</p></div></div>

<div class="card">
    <h2 class="panel-title">New request</h2>
    <form method="post" action="<?= url('me/hr/save') ?>">
        <?= csrf_field() ?>
        <div class="inline">
            <div class="field" style="min-width:220px"><label>Category</label>
                <select name="category">
                    <?php foreach ($categories as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field" style="min-width:320px"><label>Subject</label><input name="subject" required></div>
        </div>
        <div class="field"><label>Message</label><textarea name="message" rows="3"></textarea></div>
        <div class="actions"><button type="submit">Send to HR</button></div>
    </form>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Date</th><th>Category</th><th>Subject</th><th>Status</th><th>HR response</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): $st = (int) $r['StateID']; ?>
        <tr>
            <td class="subtle"><?= date('d/m/Y', strtotime((string) $r['CreatedAt'])) ?></td>
            <td><?= e($r['Category']) ?></td>
            <td><?= e($r['Subject']) ?></td>
            <td><span class="chip <?= $st >= HR::RESOLVED ? 'present' : 'pending' ?>">
                <?= e(HR::STATE_LABELS[$st] ?? $st) ?></span></td>
            <td class="subtle"><?= e($r['Response']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$requests): ?><tr><td colspan="5" class="center subtle">No requests yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
