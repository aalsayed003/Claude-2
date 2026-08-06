<?php
use App\Core\Config;
use App\Payroll\Repositories\PayrollRepository as PR;

$components = (array) Config::get('payroll.components', []);
$earnings   = array_filter($components, fn($c) => ($c['type'] ?? 'earning') === 'earning');
$deductions = array_filter($components, fn($c) => ($c['type'] ?? 'earning') === 'deduction');

// Only show columns that actually carry a value this month — the register has
// 30-odd possible components and most departments use a handful.
$used = [];
foreach ($rows as $r) {
    foreach ($components as $k => $c) {
        if ((float) ($r[$c['register']] ?? 0) != 0.0) { $used[$k] = true; }
    }
}
$earnings   = array_intersect_key($earnings, $used);
$deductions = array_intersect_key($deductions, $used);
$monthParam = date('Y-m', strtotime($month));
?>
<div class="page-head">
    <div>
        <h1>Payroll Register</h1>
        <p class="subtle"><?= date('F Y', strtotime($month)) ?>
            <?php if ($run): ?>· <span class="chip"><?= e(PR::STATE_LABELS[(int) $run['StateID']] ?? '') ?></span><?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <a class="btn-ghost btn-sm" href="<?= url('payroll/register?month=' . $monthParam . '&department_id=' . (int) $departmentId . '&export=csv') ?>">Export CSV</a>
        <?php if ($run): ?><a class="btn-ghost btn-sm" href="<?= url('payroll/run?id=' . $run['RunID']) ?>">Run</a><?php endif; ?>
    </div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/register') ?>" class="inline">
    <div class="field"><label>Month</label><input type="month" name="month" value="<?= e($monthParam) ?>"></div>
    <div class="field" style="min-width:240px"><label>Department</label>
        <select name="department_id">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= $departmentId == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button type="submit">Show</button>
</form>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead>
        <tr>
            <th rowspan="2">Code</th><th rowspan="2">Employee</th><th rowspan="2">Department</th>
            <th colspan="3" class="center">Days</th>
            <?php if ($earnings): ?><th colspan="<?= count($earnings) ?>" class="center">Earnings</th><?php endif; ?>
            <?php if ($deductions): ?><th colspan="<?= count($deductions) ?>" class="center">Deductions</th><?php endif; ?>
            <th rowspan="2" class="num">Gross</th>
            <th rowspan="2" class="num">Deducted</th>
            <th rowspan="2" class="num">Net</th>
            <th rowspan="2"></th>
        </tr>
        <tr>
            <th class="num">Paid</th><th class="num">Absent</th><th class="num">Leave</th>
            <?php foreach ($earnings as $c): ?><th class="num"><?= e($c['label']) ?></th><?php endforeach; ?>
            <?php foreach ($deductions as $c): ?><th class="num"><?= e($c['label']) ?></th><?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    $tG = $tD = $tN = 0.0;
    foreach ($rows as $r):
        $tG += (float) $r['TotalEarnings']; $tD += (float) $r['TotalDeduction']; $tN += (float) $r['NetPayment'];
    ?>
        <tr>
            <td><?= e($r['emp_code']) ?></td>
            <td><?= e($r['emp_name']) ?></td>
            <td class="subtle"><?= e($r['dept_name']) ?></td>
            <td class="num"><?= (float) $r['payabledays'] ?></td>
            <td class="num <?= (float) $r['absentdays'] ? 'late' : '' ?>"><?= (float) $r['absentdays'] ?: '' ?></td>
            <td class="num"><?= (float) $r['LEAVE'] ?: '' ?></td>
            <?php foreach ($earnings as $c): $v = (float) ($r[$c['register']] ?? 0); ?>
                <td class="num"><?= $v ? money($v) : '' ?></td>
            <?php endforeach; ?>
            <?php foreach ($deductions as $c): $v = (float) ($r[$c['register']] ?? 0); ?>
                <td class="num"><?= $v ? money($v) : '' ?></td>
            <?php endforeach; ?>
            <td class="num"><?= money($r['TotalEarnings']) ?></td>
            <td class="num"><?= money($r['TotalDeduction']) ?></td>
            <td class="num"><strong><?= money($r['NetPayment']) ?></strong></td>
            <td><a class="btn-ghost btn-sm"
                   href="<?= url('payroll/payslip?employee_id=' . (int) $r['Empid'] . '&month=' . $monthParam) ?>">Slip</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="20" class="center subtle">Nothing posted for this month yet. Calculate the run first.</td></tr>
    <?php else: ?>
        <tr>
            <td colspan="<?= 6 + count($earnings) + count($deductions) ?>" class="num"><strong>Total — <?= count($rows) ?> employees</strong></td>
            <td class="num"><strong><?= money($tG) ?></strong></td>
            <td class="num"><strong><?= money($tD) ?></strong></td>
            <td class="num"><strong><?= money($tN) ?></strong></td>
            <td></td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
