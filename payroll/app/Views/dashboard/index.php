<?php use App\Repositories\PayrollRepository as PR; ?>
<div class="page-head">
    <div><h1>Payroll</h1><p class="subtle">Standalone — salary structures, runs, register, payslips and the bank file.</p></div>
</div>

<?php if (!$canView): ?>
    <div class="card">
        <p>Your account does not have payroll access. You can still view your own payslip.</p>
        <a class="btn" href="<?= url('payroll/payslip') ?>">My Payslip</a>
    </div>
<?php else: ?>

<div class="tiles">
    <div class="tile"><span class="subtle">Employees</span><strong><?= (int) ($counts['employees'] ?? 0) ?></strong></div>
    <div class="tile"><span class="subtle">With a salary structure</span><strong><?= (int) ($counts['structures'] ?? 0) ?></strong></div>
    <div class="tile"><span class="subtle">Active loans</span><strong><?= (int) ($counts['loans'] ?? 0) ?></strong></div>
    <div class="tile"><span class="subtle">Salaries held</span><strong><?= (int) ($counts['held'] ?? 0) ?></strong></div>
</div>

<div class="card">
    <h2 class="panel-title">Get started</h2>
    <div class="actions">
        <a class="btn" href="<?= url('payroll') ?>">Payroll runs</a>
        <a class="btn-ghost" href="<?= url('payroll/structures') ?>">Salary structures</a>
        <a class="btn-ghost" href="<?= url('payroll/register') ?>">Register</a>
        <a class="btn-ghost" href="<?= url('payroll/loans') ?>">Loans</a>
        <a class="btn-ghost" href="<?= url('payroll/holds') ?>">Hold &amp; release</a>
        <a class="btn-ghost" href="<?= url('payroll/encashment') ?>">Leave encashment</a>
        <a class="btn-ghost" href="<?= url('payroll/settlement') ?>">Settlement</a>
    </div>
</div>

<div class="card">
    <h2 class="panel-title">Recent payroll months</h2>
    <table class="tbl">
        <thead><tr><th>Month</th><th>State</th><th class="num">Employees</th><th class="num">Net</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): $st = (int) $r['StateID']; ?>
            <tr>
                <td><strong><?= date('F Y', strtotime((string) $r['PayrollMonth'])) ?></strong></td>
                <td><span class="chip <?= $st >= PR::LOCKED ? 'day_off' : ($st >= PR::APPROVED ? 'present' : 'pending') ?>">
                    <?= e(PR::STATE_LABELS[$st] ?? $st) ?></span></td>
                <td class="num"><?= (int) $r['EmployeeCount'] ?></td>
                <td class="num"><?= $r['NetPayment'] !== null ? money($r['NetPayment']) : '' ?></td>
                <td><a class="btn-ghost btn-sm" href="<?= url('payroll/run?id=' . $r['RunID']) ?>">Open</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$runs): ?>
            <tr><td colspan="5" class="center subtle">No payroll months yet.
                <a href="<?= url('payroll') ?>">Open the first one.</a></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>
