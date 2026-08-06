<?php $asOfParam = date('Y-m-d', strtotime($asOf)); ?>
<div class="page-head">
    <div>
        <h1>Indemnity Provision</h1>
        <p class="subtle">Accrued end-of-service liability for every active employee, on <?= e($basisLabel) ?> wage.
            Same rules as the settlement, so it reconciles to what would be paid.</p>
    </div>
    <div class="actions">
        <a class="btn-ghost btn-sm"
           href="<?= url('payroll/indemnity?as_of=' . $asOfParam . '&department_id=' . (int) $deptId . '&compare=' . urlencode($compare) . '&export=csv') ?>">Export CSV</a>
    </div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/indemnity') ?>" class="inline">
    <div class="field"><label>As of date</label>
        <input type="date" name="as_of" value="<?= e($asOfParam) ?>"></div>
    <div class="field" style="min-width:220px"><label>Department</label>
        <select name="department_id">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $deptId == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field" style="min-width:200px"><label>Compare to snapshot</label>
        <select name="compare">
            <option value="">— none —</option>
            <?php foreach ($snapshots as $s): ?>
                <option value="<?= e($s) ?>" <?= $compare === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button type="submit">Show</button>
</form>
</div>

<div class="tiles">
    <div class="tile"><span class="subtle">Active employees</span><strong><?= (int) $data['totals']['count'] ?></strong></div>
    <div class="tile"><span class="subtle">Accrued days</span><strong><?= number_format($data['totals']['days'], 1) ?></strong></div>
    <div class="tile"><span class="subtle">Total provision</span><strong><?= money($data['totals']['amount']) ?></strong>
        <span class="subtle"><?= e(\App\Core\Config::get('payroll.currency', 'BHD')) ?></span></div>
    <?php if ($hasMovement): ?>
    <div class="tile"><span class="subtle">Movement since <?= e($compare) ?></span>
        <strong><?= ($movementTotal >= 0 ? '+' : '') . money($movementTotal) ?></strong>
        <span class="subtle">period charge</span></div>
    <?php endif; ?>
</div>

<?php if ($canProcess): ?>
<div class="card" style="padding:12px 18px">
    <form method="post" action="<?= url('payroll/indemnity/snapshot') ?>" class="inline"
          onsubmit="return confirm('Save the provision for <?= e($asOfParam) ?> as a snapshot? It replaces any snapshot already saved for that date.')">
        <?= csrf_field() ?>
        <input type="hidden" name="as_of" value="<?= e($asOfParam) ?>">
        <button type="submit">Save snapshot for <?= e($asOfParam) ?></button>
        <span class="subtle">Keeps this month's balance so next month's charge can be measured against it.</span>
    </form>
</div>
<?php endif; ?>

<?php if ($data['issues']): ?>
<div class="card">
    <h2 class="panel-title"><?= count($data['issues']) ?> employees need attention</h2>
    <p class="subtle">These accrue nothing until fixed — no joining date means no service length, no structure means no wage.</p>
    <table class="tbl">
        <thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Problem</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($data['issues'], 0, 40) as $x): ?>
            <tr><td><?= e($x['emp_code']) ?></td><td><?= e($x['full_name']) ?></td>
                <td class="subtle"><?= e($x['dept_name']) ?></td><td><?= e($x['problem']) ?></td>
                <td><a class="btn-ghost btn-sm" href="<?= url('payroll/structure?employee_id=' . (int) $x['id']) ?>">Fix</a></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr>
        <th>Code</th><th>Employee</th><th>Department</th><th>Joined</th><th>Service</th>
        <th class="num">Wage</th><th class="num">Accrued days</th><th class="num">Provision</th>
        <?php if ($hasMovement): ?><th class="num">Prior</th><th class="num">Movement</th><?php endif; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($data['rows'] as $r): ?>
        <tr>
            <td><?= e($r['emp_code']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <td class="subtle"><?= e($r['dept_name']) ?></td>
            <td class="subtle"><?= date('d/m/Y', strtotime($r['joining'])) ?></td>
            <td class="subtle"><?= e($r['service']) ?></td>
            <td class="num"><?= money($r['wage']) ?></td>
            <td class="num"><?= number_format($r['days'], 1) ?></td>
            <td class="num"><strong><?= money($r['amount']) ?></strong></td>
            <?php if ($hasMovement): ?>
                <td class="num subtle"><?= money($r['prior'] ?? 0) ?></td>
                <td class="num <?= ($r['movement'] ?? 0) < 0 ? 'late' : '' ?>">
                    <?= (($r['movement'] ?? 0) >= 0 ? '+' : '') . money($r['movement'] ?? 0) ?></td>
            <?php endif; ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$data['rows']): ?>
        <tr><td colspan="<?= $hasMovement ? 10 : 8 ?>" class="center subtle">No active employees for this filter.</td></tr>
    <?php else: ?>
        <tr>
            <td colspan="7" class="num"><strong>Total — <?= (int) $data['totals']['count'] ?> employees</strong></td>
            <td class="num"><strong><?= money($data['totals']['amount']) ?></strong></td>
            <?php if ($hasMovement): ?>
                <td></td>
                <td class="num"><strong><?= ($movementTotal >= 0 ? '+' : '') . money($movementTotal) ?></strong></td>
            <?php endif; ?>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<p class="muted-note">
    Indemnity accrues at <?= (int) \App\Core\Config::get('payroll.indemnity.days_first_tier', 15) ?> days per year for the first
    <?= (int) \App\Core\Config::get('payroll.indemnity.first_tier_years', 3) ?> years and
    <?= (int) \App\Core\Config::get('payroll.indemnity.days_after_tier', 30) ?> days per year thereafter, on <?= e($basisLabel) ?> wage ÷
    <?= (int) \App\Core\Config::get('payroll.fixed_month_days', 30) ?>.
    For staff whose service spans Bahrain's 2024 SIO end-of-service reform, part of the post-reform balance may already be funded by
    monthly SIO contributions — this figure is the gross entitlement.
</p>
