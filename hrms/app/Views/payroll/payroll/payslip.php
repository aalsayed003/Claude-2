<?php
use App\Payroll\Repositories\PayrollRepository as PR;

$monthParam = date('Y-m', strtotime($month));

/** Build [label => amount] lists from either a posted register row or a preview. */
$lines = function (string $type) use ($components, $row, $preview): array {
    $out = [];
    foreach ($components as $key => $c) {
        if (($c['type'] ?? 'earning') !== $type) { continue; }
        $v = $row !== null
            ? (float) ($row[$c['register']] ?? 0)
            : (float) ($preview['components'][$key] ?? 0);
        if ($v != 0.0) { $out[$c['label']] = $v; }
    }
    return $out;
};
$hasFigures = $emp && ($row !== null || $preview !== null);
$earnLines = $hasFigures ? $lines('earning') : [];
$dedLines  = $hasFigures ? $lines('deduction') : [];
$totalE = $row ? (float) $row['TotalEarnings']  : (float) ($preview['totals']['earnings'] ?? 0);
$totalD = $row ? (float) $row['TotalDeduction'] : (float) ($preview['totals']['deductions'] ?? 0);
$net    = $row ? (float) $row['NetPayment']     : (float) ($preview['totals']['net'] ?? 0);
?>
<div class="page-head">
    <div><h1>Payslip</h1><p class="subtle"><?= date('F Y', strtotime($month)) ?></p></div>
    <?php if ($row): ?>
        <a class="btn-ghost btn-sm" target="_blank"
           href="<?= url('payroll/payslip/print?employee_id=' . (int) $emp['id'] . '&month=' . $monthParam) ?>">Print</a>
    <?php endif; ?>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/payslip') ?>" class="inline">
    <?php if ($canPickAnyone): ?>
    <div class="field" style="min-width:300px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?>
                <option value="<?= (int) $e1['id'] ?>" <?= ($emp && (int) $emp['id'] === (int) $e1['id']) ? 'selected' : '' ?>>
                    <?= e($e1['emp_id'] . ' — ' . $e1['full_name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="field"><label>Month</label><input type="month" name="month" value="<?= e($monthParam) ?>"></div>
    <button type="submit">Show</button>
</form>
</div>

<?php if (!$emp): ?>
    <div class="card subtle">Pick an employee and a month.</div>
<?php elseif (!$row && !$preview): ?>
    <div class="card subtle">
        Nothing posted for <?= e($emp['full_name']) ?> in <?= date('F Y', strtotime($month)) ?>.
        <?php if ($run): ?>
            The run is <?= e(PR::STATE_LABELS[(int) $run['StateID']] ?? '') ?>.
        <?php else: ?>
            No payroll run has been opened for this month.
        <?php endif; ?>
    </div>
<?php else: ?>

<?php if (!$row): ?>
    <div class="flash flash-error">Preview only — this month has not been calculated and posted.
        The figures below are what the engine would produce right now.</div>
<?php endif; ?>

<div class="card" style="padding:12px 18px">
    <strong><?= e($emp['emp_code'] . ' · ' . $emp['full_name']) ?></strong>
    <span class="subtle"> — <?= e($emp['dept_name']) ?></span>
</div>

<div class="grid">
    <div class="card">
        <h2 class="panel-title">Earnings</h2>
        <table class="tbl">
            <tbody>
            <?php foreach ($earnLines as $label => $v): ?>
                <tr><td><?= e($label) ?></td><td class="num"><?= money($v) ?></td></tr>
            <?php endforeach; ?>
            <tr><td><strong>Total earnings</strong></td><td class="num"><strong><?= money($totalE) ?></strong></td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2 class="panel-title">Deductions</h2>
        <table class="tbl">
            <tbody>
            <?php foreach ($dedLines as $label => $v): ?>
                <tr><td><?= e($label) ?></td><td class="num"><?= money($v) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$dedLines): ?><tr><td class="subtle" colspan="2">None</td></tr><?php endif; ?>
            <tr><td><strong>Total deductions</strong></td><td class="num"><strong><?= money($totalD) ?></strong></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="tiles">
    <div class="tile"><span class="subtle">Net payment</span><strong><?= money($net) ?></strong>
        <span class="subtle"><?= e(\App\Core\Config::get('payroll.currency', 'BHD')) ?></span></div>
    <div class="tile"><span class="subtle">Payable days</span>
        <strong><?= (float) ($row['payabledays'] ?? $preview['totals']['payable_days'] ?? 0) ?></strong></div>
    <div class="tile"><span class="subtle">Days attended</span>
        <strong><?= (float) ($row['NoofDaysattended'] ?? $preview['summary']['present_days'] ?? 0) ?></strong></div>
    <div class="tile"><span class="subtle">Absent</span>
        <strong><?= (float) ($row['absentdays'] ?? $preview['summary']['absent_days'] ?? 0) ?></strong></div>
</div>

<?php if ($preview): ?>
<div class="card">
    <h2 class="panel-title">How this was calculated</h2>
    <div class="grid">
        <table class="tbl"><tbody>
            <tr><td>Divisor (days per month)</td><td class="num"><?= $preview['rates']['divisor'] ?></td></tr>
            <tr><td>Employment factor</td><td class="num"><?= $preview['rates']['employment_factor'] ?></td></tr>
            <tr><td>Full basic / gross</td><td class="num"><?= money($preview['rates']['full_basic']) ?> / <?= money($preview['rates']['full_gross']) ?></td></tr>
            <tr><td>Day rate</td><td class="num"><?= money($preview['rates']['day_rate']) ?></td></tr>
            <tr><td>Hour rate (overtime base)</td><td class="num"><?= money($preview['rates']['hour_rate']) ?></td></tr>
        </tbody></table>
        <table class="tbl"><tbody>
            <tr><td>Scheduled days</td><td class="num"><?= $preview['summary']['scheduled_days'] ?></td></tr>
            <tr><td>Day off / not rostered</td><td class="num"><?= $preview['summary']['off_days'] ?> / <?= $preview['summary']['unrostered_days'] ?></td></tr>
            <tr><td>Paid / unpaid leave days</td><td class="num"><?= $preview['summary']['paid_leave_days'] ?> / <?= $preview['summary']['unpaid_leave_days'] ?></td></tr>
            <tr><td>Late minutes (<?= $preview['summary']['late_days'] ?> days)</td><td class="num"><?= $preview['summary']['late_minutes'] ?></td></tr>
            <tr><td>Early-out minutes (<?= $preview['summary']['undertime_days'] ?> days)</td><td class="num"><?= $preview['summary']['undertime_minutes'] ?></td></tr>
        </tbody></table>
    </div>

    <?php if (!empty($preview['summary']['late_detail'])): ?>
        <h3 class="panel-title">Late-in / early-out — day by day</h3>
        <p class="muted-note" style="margin-top:0">These are the exact days behind the late/early-out deductions,
            using the same punches and grace shown on the Attendance screen. A ⚠ marks a day with an odd number of
            punches (a missing in/out) — worth an attendance correction.</p>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr><th>Date</th><th>Scheduled</th><th>Actual</th>
                <th class="num">Late (min)</th><th class="num">Early-out (min)</th><th>Note</th></tr></thead>
            <tbody>
            <?php foreach ($preview['summary']['late_detail'] as $d): ?>
                <tr>
                    <td><?= e(date('d/m/Y D', strtotime($d['date']))) ?></td>
                    <td class="subtle"><?= e(($d['sched_in'] ?: '—') . ' → ' . ($d['sched_out'] ?: '—')) ?></td>
                    <td><?= e(($d['act_in'] ?: '—') . ' → ' . ($d['act_out'] ?: '—')) ?></td>
                    <td class="num"><?= $d['late'] ?: '' ?></td>
                    <td class="num"><?= $d['early'] ?: '' ?></td>
                    <td><?= $d['odd'] ? '<span style="color:#c0392b">⚠ odd punch</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <?php if ($preview['ot_detail']): ?>
        <h3 class="panel-title">Overtime</h3>
        <table class="tbl">
            <thead><tr><th>Day type</th><th class="num">Minutes</th><th class="num">Multiplier</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach ($preview['ot_detail'] as $type => $d): ?>
                <tr><td><?= e(ucfirst($type)) ?></td>
                    <td class="num"><?= $d['minutes'] ?></td>
                    <td class="num">×<?= $d['multiplier'] ?></td>
                    <td class="num"><?= money($d['amount']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($preview['gosi']['wage'])): $g = $preview['gosi']; ?>
        <p class="muted-note">
            GOSI (<?= e(ucfirst($g['category'] ?? '')) ?>): <?= money($g['wage']) ?> contributory wage ·
            <?php if (!empty($g['unemployment_pct'])): ?>
                social insurance <?= $g['social_pct'] ?>% = <?= money($g['social_employee']) ?>
                + unemployment <?= $g['unemployment_pct'] ?>% = <?= money($g['unemployment_employee']) ?>
                = employee <?= $g['employee_pct'] ?>% (<?= money($g['employee']) ?>)
            <?php else: ?>
                employee <?= $g['employee_pct'] ?>% = <?= money($g['employee']) ?>
            <?php endif; ?>
            · employer <?= $g['employer_pct'] ?>% = <?= money($g['employer']) ?>
            <span class="subtle">(<?= e($g['source']) ?>)</span>
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
