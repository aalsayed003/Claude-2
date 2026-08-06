<?php
use App\Repositories\SalaryStructureRepository as SS;
$monthParam = date('Y-m', strtotime($month));
$gross = SS::grossOf($current);
?>
<div class="page-head">
    <div>
        <h1><?= e($emp['full_name']) ?></h1>
        <p class="subtle"><?= e($emp['emp_code']) ?> · <?= e($emp['dept_name']) ?>
            <?php if ($current): ?>
                · structure in force from <?= date('M Y', strtotime((string) $current['CurrentMonth'])) ?>
                (gross <?= money($gross) ?>)
            <?php else: ?>
                · <span class="chip pending">no structure yet</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <a class="btn-ghost btn-sm" href="<?= url('payroll/increment?employee_id=' . (int) $emp['id']) ?>">Increment</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/payslip?employee_id=' . (int) $emp['id'] . '&month=' . $monthParam) ?>">Payslip</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/loans?employee_id=' . (int) $emp['id']) ?>">Loans</a>
        <a class="btn-ghost btn-sm" href="<?= url('payroll/structures') ?>">Back</a>
    </div>
</div>

<div class="grid">
<div class="card">
    <h2 class="panel-title">Salary structure</h2>
    <form method="post" action="<?= url('payroll/structure/save') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">
        <div class="field"><label>Effective from month</label>
            <input type="month" name="effective_month" value="<?= e($monthParam) ?>" required>
            <span class="subtle">A new row is created for this month; earlier months keep their figures.</span>
        </div>

        <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Component</th><th class="num">Amount</th><th class="center">Prorated</th><th class="center">GOSI</th></tr></thead>
            <tbody>
            <?php $total = 0.0; foreach ($components as $key => $c):
                $v = (float) ($current[$c['structure']] ?? 0); $total += $v; ?>
                <tr>
                    <td><?= e($c['label']) ?></td>
                    <td class="num"><input type="number" step="0.001" min="0" style="width:120px;text-align:right"
                            name="c[<?= e($key) ?>]" value="<?= $v ?: '' ?>"></td>
                    <td class="center"><?= !empty($c['prorate']) ? '<span class="chip">yes</span>' : '' ?></td>
                    <td class="center"><?= !empty($c['gosi']) ? '<span class="chip present">yes</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            <tr><td><strong>Gross</strong></td><td class="num"><strong><?= money($total) ?></strong></td><td colspan="2"></td></tr>
            </tbody>
        </table>
        </div>
        <div class="actions"><button type="submit">Save structure</button></div>
    </form>
</div>

<div class="card">
    <h2 class="panel-title">Statutory &amp; banking</h2>
    <form method="post" action="<?= url('payroll/statutory/save') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">

        <div class="checkbox"><label>
            <input type="checkbox" name="is_bahraini" value="1" <?= !empty($statutory['IsBahraini']) ? 'checked' : '' ?>>
            Bahraini national <span class="subtle">— selects the GOSI rate set</span></label></div>
        <div class="checkbox"><label>
            <input type="checkbox" name="exclude_gosi" value="1" <?= !empty($statutory['ExcludeGosi']) ? 'checked' : '' ?>>
            Exclude from GOSI</label></div>

        <div class="field"><label>CPR</label>
            <input type="text" name="cpr" value="<?= e($statutory['CPR'] ?? '') ?>"></div>
        <div class="field"><label>GOSI number</label>
            <input type="text" name="gosi_number" value="<?= e($statutory['GosiNumber'] ?? '') ?>"></div>
        <div class="field"><label>LMRA ID</label>
            <input type="text" name="lmra_id" value="<?= e($statutory['LmraId'] ?? '') ?>"></div>

        <div class="field"><label>Payment mode</label>
            <select name="payment_mode">
                <?php foreach ([1 => 'Bank transfer', 2 => 'Cash', 3 => 'Cheque'] as $k => $v): ?>
                    <option value="<?= $k ?>" <?= (int) ($statutory['PaymentMode'] ?? 1) === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Bank</label>
            <select name="bank_id">
                <option value="">—</option>
                <?php foreach ($banks as $b): ?>
                    <option value="<?= (int) $b['BankID'] ?>" <?= (int) ($statutory['BankID'] ?? 0) === (int) $b['BankID'] ? 'selected' : '' ?>>
                        <?= e($b['Name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>IBAN</label>
            <input type="text" name="iban" value="<?= e($statutory['IBAN'] ?? '') ?>" placeholder="BH.."></div>
        <div class="field"><label>Account number</label>
            <input type="text" name="account_no" value="<?= e($statutory['AccountNo'] ?? '') ?>"></div>

        <div class="field"><label>Joining date <span class="subtle">(overrides the HR master, used for indemnity)</span></label>
            <input type="date" name="joining_date"
                   value="<?= e($statutory['JoiningDate'] ? date('Y-m-d', strtotime((string) $statutory['JoiningDate'])) : ($emp['joined_at'] ? date('Y-m-d', strtotime((string) $emp['joined_at'])) : '')) ?>"></div>
        <div class="field"><label>Contract type</label>
            <select name="contract_type">
                <option value="">—</option>
                <option value="1" <?= (int) ($statutory['ContractType'] ?? 0) === 1 ? 'selected' : '' ?>>Unlimited</option>
                <option value="2" <?= (int) ($statutory['ContractType'] ?? 0) === 2 ? 'selected' : '' ?>>Limited</option>
            </select></div>

        <div class="actions"><button type="submit">Save details</button></div>
    </form>
</div>
</div>

<div class="card">
    <h2 class="panel-title">Structure history</h2>
    <div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>Effective from</th>
            <?php foreach ($components as $c): ?><th class="num"><?= e($c['label']) ?></th><?php endforeach; ?>
            <th class="num">Gross</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><strong><?= date('M Y', strtotime((string) $h['CurrentMonth'])) ?></strong></td>
                <?php foreach ($components as $c): $v = (float) ($h[$c['structure']] ?? 0); ?>
                    <td class="num"><?= $v ? money($v) : '' ?></td>
                <?php endforeach; ?>
                <td class="num"><strong><?= money(\App\Repositories\SalaryStructureRepository::grossOf($h)) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$history): ?>
            <tr><td colspan="<?= count($components) + 2 ?>" class="center subtle">No structure recorded yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
