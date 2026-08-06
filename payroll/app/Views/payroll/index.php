<?php use App\Repositories\PayrollRepository as PR; ?>
<div class="page-head">
    <div><h1>Payroll</h1><p class="subtle">One run per payroll month, calculated from the roster cycle.</p></div>
</div>

<div class="card" style="padding:10px 14px">
    <div class="actions">
        <a class="btn-ghost btn-sm" href="<?= url('payroll/register') ?>">Register</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/structures') ?>">Salary structures</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/loans') ?>">Loans</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/holds') ?>">Hold &amp; release</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/encashment') ?>">Leave encashment</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/indemnity') ?>">Indemnity provision</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/leave-provision') ?>">Leave provision</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/settlement') ?>">Settlement</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/employees') ?>">Employees</a>
        <a class="btn-ghost btn-sm" href="<?= url('hr/leave') ?>">HR desk</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/payslip') ?>">Payslip</a>
    </div>
</div>

<?php if ($canProcess): ?>
<div class="card">
    <form method="post" action="<?= url('payroll/create') ?>" class="inline">
        <?= csrf_field() ?>
        <div class="field"><label>Open payroll month</label>
            <input type="month" name="payroll_month" value="<?= e(date('Y-m', strtotime($suggest))) ?>" required>
        </div>
        <button type="submit">Open month</button>
        <span class="subtle">Creates the run header for the matching attendance cycle.</span>
    </form>
</div>
<?php endif; ?>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr>
        <th>Payroll month</th><th>Attendance cycle</th><th>State</th>
        <th class="num">Employees</th><th class="num">Earnings</th>
        <th class="num">Deductions</th><th class="num">Net</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($runs as $r): $st = (int) $r['StateID']; ?>
        <tr>
            <td><strong><?= date('F Y', strtotime((string) $r['PayrollMonth'])) ?></strong></td>
            <td class="subtle">
                <?= date('d/m/Y', strtotime((string) $r['PeriodFrom'])) ?> —
                <?= date('d/m/Y', strtotime((string) $r['PeriodTo'])) ?>
            </td>
            <td><span class="chip <?= $st >= PR::LOCKED ? 'day_off' : ($st >= PR::APPROVED ? 'present' : 'pending') ?>">
                <?= e(PR::STATE_LABELS[$st] ?? $st) ?></span></td>
            <td class="num"><?= (int) $r['EmployeeCount'] ?></td>
            <td class="num"><?= $r['TotalEarnings'] !== null ? money($r['TotalEarnings']) : '' ?></td>
            <td class="num"><?= $r['TotalDeduction'] !== null ? money($r['TotalDeduction']) : '' ?></td>
            <td class="num"><strong><?= $r['NetPayment'] !== null ? money($r['NetPayment']) : '' ?></strong></td>
            <td><a class="btn-ghost btn-sm" href="<?= url('payroll/run?id=' . $r['RunID']) ?>">Open</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$runs): ?>
        <tr><td colspan="8" class="center subtle">No payroll months yet. Open one above to begin.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
