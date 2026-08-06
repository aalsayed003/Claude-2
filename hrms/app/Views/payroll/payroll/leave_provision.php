<?php $asOfParam = date('Y-m-d', strtotime($asOf)); ?>
<div class="page-head">
    <div><h1>Leave Provision</h1>
        <p class="subtle">Accrued untaken annual leave for every active employee, valued on latest <?= e($data['basis']) ?> salary.</p></div>
    <div class="actions">
        <a class="btn-ghost btn-sm"
           href="<?= url('payroll/leave-provision?as_of=' . $asOfParam . '&department_id=' . (int) $deptId . '&export=csv') ?>">Export CSV</a>
    </div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/leave-provision') ?>" class="inline">
    <div class="field"><label>As of date</label><input type="date" name="as_of" value="<?= e($asOfParam) ?>"></div>
    <div class="field" style="min-width:220px"><label>Department</label>
        <select name="department_id">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $deptId == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button type="submit">Show</button>
</form>
</div>

<div class="tiles">
    <div class="tile"><span class="subtle">Active employees</span><strong><?= (int) $data['totals']['count'] ?></strong></div>
    <div class="tile"><span class="subtle">Balance days</span><strong><?= number_format($data['totals']['balance'], 1) ?></strong></div>
    <div class="tile"><span class="subtle">Total provision</span><strong><?= money($data['totals']['amount']) ?></strong>
        <span class="subtle"><?= e(\App\Core\Config::get('payroll.currency', 'BHD')) ?></span></div>
</div>

<?php if ($canProcess): ?>
<div class="card" style="padding:12px 18px">
    <form method="post" action="<?= url('payroll/leave-provision/snapshot') ?>" class="inline"
          onsubmit="return confirm('Save the leave provision for <?= e($asOfParam) ?> as a snapshot?')">
        <?= csrf_field() ?><input type="hidden" name="as_of" value="<?= e($asOfParam) ?>">
        <button type="submit">Save snapshot for <?= e($asOfParam) ?></button>
    </form>
</div>
<?php endif; ?>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr>
        <th>Code</th><th>Employee</th><th>Department</th>
        <th class="num">Basic</th><th class="num">Entitled</th><th class="num">Used</th>
        <th class="num">Balance</th><th class="num">Forfeited</th><th class="num">Day rate</th><th class="num">Provision</th>
    </tr></thead>
    <tbody>
    <?php foreach ($data['rows'] as $r): ?>
        <tr>
            <td><?= e($r['emp_code']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <td class="subtle"><?= e($r['dept_name']) ?></td>
            <td class="num"><?= money($r['basic']) ?></td>
            <td class="num"><?= number_format($r['entitled'], 1) ?></td>
            <td class="num"><?= number_format($r['used'], 1) ?></td>
            <td class="num"><strong><?= number_format($r['balance'], 1) ?></strong></td>
            <td class="num <?= $r['forfeited'] > 0 ? 'late' : '' ?>"><?= $r['forfeited'] > 0 ? number_format($r['forfeited'], 1) : '' ?></td>
            <td class="num"><?= money($r['day_rate']) ?></td>
            <td class="num"><strong><?= money($r['amount']) ?></strong></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$data['rows']): ?>
        <tr><td colspan="10" class="center subtle">No active employees for this filter.</td></tr>
    <?php else: ?>
        <tr><td colspan="9" class="num"><strong>Total — <?= (int) $data['totals']['count'] ?> employees</strong></td>
            <td class="num"><strong><?= money($data['totals']['amount']) ?></strong></td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<p class="muted-note">
    Entitlement <?= (int) \App\Core\Config::get('payroll.leave_provision.annual_entitlement_days', 30) ?> days/year,
    pro-rated to service; balance above the <?= (int) \App\Core\Config::get('payroll.leave_provision.carryover_cap_days', 60) ?>-day
    carry-over cap is forfeited and not provided for. Balance and used come from the HR leave tables when the link is live;
    otherwise they derive from entitlement.
</p>
