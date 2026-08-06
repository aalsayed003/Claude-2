<?php use App\Payroll\Repositories\CmeRepository as CME; ?>
<div class="page-head">
    <div><h1>My Training (CME)</h1><p class="subtle">Continuing education hours required and completed for <?= (int) $year ?>.</p></div>
    <form method="get" action="<?= url('me/cme') ?>" class="inline">
        <div class="field"><label>Year</label>
            <select name="year" onchange="this.form.submit()">
                <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 4; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === (int) $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select></div>
    </form>
</div>

<div class="tiles">
    <div class="tile"><span class="subtle">Required</span><strong><?= number_format($required, 1) ?></strong><span class="subtle">hours</span></div>
    <div class="tile"><span class="subtle">Completed</span><strong><?= number_format($recorded, 1) ?></strong>
        <span class="subtle"><?= number_format($verified, 1) ?> verified</span></div>
    <div class="tile"><span class="subtle">Remaining</span><strong><?= number_format(max(0, $required - $recorded), 1) ?></strong></div>
    <div class="tile"><span class="subtle">Progress</span><strong><?= $pct ?>%</strong></div>
</div>

<div class="card">
    <div style="background:#eef2f7;border-radius:8px;height:18px;overflow:hidden">
        <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 100 ? 'var(--ok)' : 'var(--accent)' ?>"></div>
    </div>
</div>

<div class="card">
    <h2 class="panel-title">Log a training activity</h2>
    <form method="post" action="<?= url('me/cme/save') ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="year" value="<?= (int) $year ?>">
        <div class="field" style="min-width:280px"><label>Activity / course title</label><input name="title" required></div>
        <div class="field"><label>Provider</label><input name="provider" style="width:160px"></div>
        <div class="field"><label>Hours</label><input type="number" step="0.5" min="0.5" name="hours" style="width:90px" required></div>
        <div class="field"><label>Date</label><input type="date" name="activity_date"></div>
        <button type="submit">Add</button>
    </form>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Date</th><th>Activity</th><th>Provider</th><th class="num">Hours</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($activities as $a): $st = (int) $a['StateID']; ?>
        <tr>
            <td class="subtle"><?= $a['ActivityDate'] ? date('d/m/Y', strtotime((string) $a['ActivityDate'])) : '' ?></td>
            <td><?= e($a['Title']) ?></td>
            <td class="subtle"><?= e($a['Provider']) ?></td>
            <td class="num"><?= number_format((float) $a['Hours'], 1) ?></td>
            <td><span class="chip <?= $st === CME::VERIFIED ? 'present' : ($st === CME::REJECTED ? 'day_off' : 'pending') ?>">
                <?= e(CME::STATE_LABELS[$st] ?? $st) ?></span></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$activities): ?><tr><td colspan="5" class="center subtle">No activities logged for <?= (int) $year ?>.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
