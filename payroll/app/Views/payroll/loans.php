<?php
use App\Repositories\LoanRepository as LR;
$stateLabel = [LR::ACTIVE => 'Active', LR::ON_HOLD => 'On hold', LR::SETTLED => 'Settled', LR::CANCELLED => 'Cancelled'];
?>
<div class="page-head">
    <div><h1>Loans &amp; Advances</h1><p class="subtle">Recovery is posted when the payroll month is locked, never while it is a draft.</p></div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/loans') ?>" class="inline">
    <div class="field" style="min-width:300px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?>
                <option value="<?= (int) $e1['id'] ?>" <?= ($emp && (int) $emp['id'] === (int) $e1['id']) ? 'selected' : '' ?>>
                    <?= e($e1['emp_id'] . ' — ' . $e1['full_name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <button type="submit">Show</button>
</form>
</div>

<?php if ($emp): ?>

<?php if ($canProcess): ?>
<div class="card">
    <h2 class="panel-title">New loan or advance</h2>
    <form method="post" action="<?= url('payroll/loans/save') ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">
        <div class="field"><label>Type</label>
            <select name="loan_type">
                <?php foreach ($types as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
            </select></div>
        <div class="field"><label>Reference</label><input type="text" name="reference" style="width:120px"></div>
        <div class="field"><label>Principal</label><input type="number" step="0.001" min="0" name="principal" style="width:110px" required></div>
        <div class="field"><label>Installment</label><input type="number" step="0.001" min="0" name="installment" style="width:110px" required></div>
        <div class="field"><label>Installments</label><input type="number" min="1" name="installments" style="width:90px" required></div>
        <div class="field"><label>Start month</label><input type="month" name="start_month" value="<?= e(date('Y-m')) ?>" required></div>
        <div class="field"><label>Remarks</label><input type="text" name="remarks" style="width:180px"></div>
        <button type="submit">Record loan</button>
    </form>
</div>
<?php endif; ?>

<div class="card" style="padding:12px 18px">
    <strong><?= e($emp['emp_code'] . ' · ' . $emp['full_name']) ?></strong>
    <span class="subtle"> — <?= e($emp['dept_name']) ?></span>
</div>

<?php foreach ($loans as $l): $st = (int) $l['StateID']; ?>
<div class="card">
    <h2 class="panel-title">
        <?= e($types[(int) $l['LoanType']] ?? 'Loan') ?>
        <?= $l['Reference'] ? '· ' . e($l['Reference']) : '' ?>
        <span class="chip <?= $st === LR::ACTIVE ? 'present' : ($st === LR::SETTLED ? 'day_off' : 'pending') ?>">
            <?= e($stateLabel[$st] ?? $st) ?></span>
    </h2>
    <div class="tiles">
        <div class="tile"><span class="subtle">Principal</span><strong><?= money($l['PrincipalAmount']) ?></strong></div>
        <div class="tile"><span class="subtle">Recovered</span><strong><?= money($l['RecoveredAmount']) ?></strong></div>
        <div class="tile"><span class="subtle">Outstanding</span><strong><?= money($l['outstanding']) ?></strong></div>
        <div class="tile"><span class="subtle">Installment</span><strong><?= money($l['InstallmentAmount']) ?></strong>
            <span class="subtle">× <?= (int) $l['TotalInstallments'] ?> from <?= date('M Y', strtotime((string) $l['StartMonth'])) ?></span></div>
    </div>

    <?php if ($l['installments']): ?>
    <table class="tbl">
        <thead><tr><th>Month</th><th class="num">Deducted</th><th>Run</th><th>Posted</th></tr></thead>
        <tbody>
        <?php foreach ($l['installments'] as $i): ?>
            <tr><td><?= date('M Y', strtotime((string) $i['PayrollMonth'])) ?></td>
                <td class="num"><?= money($i['Amount']) ?></td>
                <td class="subtle">#<?= (int) $i['RunID'] ?></td>
                <td class="subtle"><?= $i['PostedAt'] ? date('d/m/Y', strtotime((string) $i['PostedAt'])) : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p class="subtle">No installments recovered yet.</p>
    <?php endif; ?>

    <?php if ($canProcess && $st !== LR::SETTLED): ?>
    <div class="actions">
        <form method="post" action="<?= url('payroll/loans/state') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="loan_id" value="<?= (int) $l['LoanID'] ?>">
            <input type="hidden" name="state" value="<?= $st === LR::ACTIVE ? LR::ON_HOLD : LR::ACTIVE ?>">
            <button class="btn-muted btn-sm" type="submit"><?= $st === LR::ACTIVE ? 'Hold recovery' : 'Resume recovery' ?></button>
        </form>
        <form method="post" action="<?= url('payroll/loans/state') ?>"
              onsubmit="return confirm('Cancel this loan? Recovery stops immediately.')">
            <?= csrf_field() ?>
            <input type="hidden" name="loan_id" value="<?= (int) $l['LoanID'] ?>">
            <input type="hidden" name="state" value="<?= LR::CANCELLED ?>">
            <button class="btn-muted btn-sm" type="submit">Cancel</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if (!$loans): ?>
    <div class="card subtle">No loans on record for this employee.</div>
<?php endif; ?>

<?php else: ?>
    <div class="card subtle">Pick an employee.</div>
<?php endif; ?>
