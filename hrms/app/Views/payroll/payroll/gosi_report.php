<div class="page-head">
    <div><h1>GOSI Reconciliation</h1>
        <p class="subtle">Employee + employer contributions for the month. The <strong>total</strong> is what SIO
            invoices — compare it to the invoice. The employer share is the hospital's cost and is never deducted from staff.</p></div>
    <a class="btn-ghost btn-sm" href="<?= url('payroll/gosi-rates') ?>">GOSI rates →</a>
</div>

<div class="card" style="padding:12px 14px">
    <form method="get" action="<?= url('payroll/gosi-report') ?>" class="inline">
        <div class="field"><label>Payroll month</label>
            <input type="month" name="payroll_month" value="<?= e($month) ?>"></div>
        <button type="submit">Show</button>
    </form>
</div>

<div class="card">
    <h2 class="panel-title">Contributions — <?= e(date('F Y', strtotime($month . '-01'))) ?></h2>
    <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Category</th><th class="num">Staff</th><th class="num">Contributory wage</th>
            <th class="num">Employee %</th><th class="num">Employee</th>
            <th class="num">Employer %</th><th class="num">Employer</th><th class="num">Total (SIO)</th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): if ($c['count'] === 0) continue; ?>
            <tr>
                <td><?= e($c['label']) ?></td>
                <td class="num"><?= (int) $c['count'] ?></td>
                <td class="num"><?= money($c['wage']) ?></td>
                <td class="num"><?= rtrim(rtrim(number_format($c['emp_pct'], 3), '0'), '.') ?>%</td>
                <td class="num"><?= money($c['employee']) ?></td>
                <td class="num"><?= rtrim(rtrim(number_format($c['er_pct'], 3), '0'), '.') ?>%</td>
                <td class="num"><?= money($c['employer']) ?></td>
                <td class="num"><strong><?= money($c['employee'] + $c['employer']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($grand['count'] === 0): ?>
            <tr><td colspan="8" class="center subtle">No posted contributions for this month — calculate the payroll run first.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($grand['count'] > 0): ?>
        <tfoot><tr style="font-weight:700;background:#f2f8fe">
            <td>Total</td>
            <td class="num"><?= (int) $grand['count'] ?></td>
            <td class="num"><?= money($grand['wage']) ?></td>
            <td></td>
            <td class="num"><?= money($grand['employee']) ?></td>
            <td></td>
            <td class="num"><?= money($grand['employer']) ?></td>
            <td class="num"><?= money($grand['total']) ?></td>
        </tr></tfoot>
        <?php endif; ?>
    </table></div>
    <?php if ($grand['count'] > 0): ?>
    <p class="muted-note">
        Employee (deducted from staff): <strong><?= money($grand['employee']) ?></strong> ·
        Employer (hospital cost): <strong><?= money($grand['employer']) ?></strong> ·
        <strong>Total to reconcile with the SIO invoice: <?= money($grand['total']) ?></strong>.
    </p>
    <?php endif; ?>
</div>
