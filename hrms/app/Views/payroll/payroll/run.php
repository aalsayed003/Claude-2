<?php
use App\Payroll\Repositories\PayrollRepository as PR;
$state = (int) $run['StateID'];
?>
<div class="page-head">
    <div>
        <h1><?= date('F Y', strtotime((string) $run['PayrollMonth'])) ?> payroll</h1>
        <p class="subtle">
            Attendance cycle <?= date('d/m/Y', strtotime((string) $run['PeriodFrom'])) ?>
            — <?= date('d/m/Y', strtotime((string) $run['PeriodTo'])) ?>
            · <span class="chip <?= $state >= PR::LOCKED ? 'day_off' : ($state >= PR::APPROVED ? 'present' : 'pending') ?>">
                <?= e(PR::STATE_LABELS[$state] ?? $state) ?></span>
        </p>
    </div>
    <div class="actions">
        <a class="btn-ghost btn-sm" href="<?= url('payroll/register?month=' . date('Y-m', strtotime((string) $run['PayrollMonth']))) ?>">Register</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll') ?>">All months</a>
    </div>
</div>

<div class="tiles">
    <div class="tile"><span class="subtle">Employees on the register</span>
        <strong><?= (int) $totals['emp_count'] ?></strong>
        <span class="subtle">of <?= (int) $headcount ?> payable</span></div>
    <div class="tile"><span class="subtle">Total earnings</span>
        <strong><?= money($totals['earnings']) ?></strong></div>
    <div class="tile"><span class="subtle">Total deductions</span>
        <strong><?= money($totals['deductions']) ?></strong></div>
    <div class="tile"><span class="subtle">Net payable</span>
        <strong><?= money($totals['net']) ?></strong></div>
    <?php if (!empty($heldCount)): ?>
    <div class="tile"><span class="subtle">Salaries held</span>
        <strong><?= (int) $heldCount ?></strong>
        <span class="subtle"><a href="<?= url('payroll/holds') ?>">excluded from bank file</a></span></div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="panel-title">Actions</h2>
    <div class="actions">
        <?php if ($canProcess && $editable): ?>
            <form method="post" action="<?= url('payroll/calculate') ?>"
                  onsubmit="return confirm('Recalculate every employee for this month? The current register for the month is replaced.')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $run['RunID'] ?>">
                <button type="submit"><?= $totals['emp_count'] ? 'Recalculate' : 'Calculate' ?> payroll</button>
            </form>
        <?php endif; ?>

        <?php if ($canApprove && $state === PR::CALCULATED): ?>
            <form method="post" action="<?= url('payroll/approve') ?>"
                  onsubmit="return confirm('Approve this payroll? The register becomes read-only.')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $run['RunID'] ?>">
                <button class="btn-ok" type="submit">Approve</button>
            </form>
        <?php endif; ?>

        <?php if ($canApprove && $state === PR::APPROVED): ?>
            <form method="post" action="<?= url('payroll/lock') ?>"
                  onsubmit="return confirm('Lock the month? Loan installments are posted and the run can no longer be reopened.')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $run['RunID'] ?>">
                <button class="btn-ok" type="submit">Lock &amp; post</button>
            </form>
            <form method="post" action="<?= url('payroll/reopen') ?>"
                  onsubmit="return confirm('Reopen as a draft for correction?')">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $run['RunID'] ?>">
                <button class="btn-muted" type="submit">Reopen</button>
            </form>
        <?php endif; ?>

        <?php if ($canApprove && $state >= PR::APPROVED): ?>
            <a class="btn btn-muted" href="<?= url('payroll/wps?id=' . $run['RunID']) ?>">Bank file</a>
        <?php endif; ?>
    </div>
    <?php if (!$editable): ?>
        <p class="muted-note">This run is <?= strtolower(PR::STATE_LABELS[$state] ?? '') ?>; the register cannot be recalculated.</p>
    <?php endif; ?>
</div>

<?php if ($exceptions): ?>
<div class="card">
    <h2 class="panel-title">Before you calculate — <?= count($exceptions) ?> employees need attention</h2>
    <p class="subtle">These still calculate, but they will be paid without bank details or without GOSI.</p>
    <div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Problem</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($exceptions, 0, 50) as $x): ?>
            <tr>
                <td><?= e($x['emp_code']) ?></td>
                <td><?= e($x['full_name']) ?></td>
                <td class="subtle"><?= e($x['dept_name']) ?></td>
                <td><?= e(implode('; ', $x['problems'])) ?></td>
                <td><a class="btn-ghost btn-sm" href="<?= url('payroll/structure?employee_id=' . $x['id']) ?>">Fix</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($exceptions) > 50): ?>
        <p class="subtle"><?= count($exceptions) - 50 ?> more not shown.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($wps): ?>
<div class="card">
    <h2 class="panel-title">Bank files produced</h2>
    <table class="tbl">
        <thead><tr><th>File</th><th class="num">Records</th><th class="num">Amount</th><th>By</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($wps as $w): ?>
            <tr><td><?= e($w['FileName']) ?></td>
                <td class="num"><?= (int) $w['RecordCount'] ?></td>
                <td class="num"><?= money($w['TotalAmount']) ?></td>
                <td><?= e($w['ExportedBy']) ?></td>
                <td class="subtle"><?= date('d/m/Y H:i', strtotime((string) $w['ExportedAt'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="panel-title">History</h2>
    <table class="tbl">
        <thead><tr><th>When</th><th>Action</th><th>By</th><th>Detail</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $a): ?>
            <tr>
                <td class="subtle"><?= date('d/m/Y H:i', strtotime((string) $a['ActionDate'])) ?></td>
                <td><span class="chip"><?= e($a['ActionName']) ?></span></td>
                <td><?= e($a['UserID']) ?></td>
                <td class="subtle"><?= e($a['Remarks']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$audit): ?><tr><td colspan="4" class="center subtle">Nothing yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
