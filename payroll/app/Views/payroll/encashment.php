<?php use App\Repositories\LeaveEncashmentRepository as LE; ?>
<div class="page-head">
    <div><h1>Leave Encashment</h1>
        <p class="subtle">Pay out unused annual leave into a chosen month. Approved requests are added to that month's payroll and marked paid when it locks.</p></div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/encashment') ?>" class="inline">
    <div class="field" style="min-width:300px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?>
                <option value="<?= (int) $e1['id'] ?>" <?= ($emp && (int) $emp['id'] === (int) $e1['id']) ? 'selected' : '' ?>>
                    <?= e($e1['emp_id'] . ' — ' . $e1['full_name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field"><label>Days (preview)</label>
        <input type="number" step="0.5" name="days" value="<?= e($input['days']) ?>" style="width:100px"></div>
    <button type="submit">Show</button>
</form>
</div>

<?php if ($emp): ?>

<?php if ($preview): ?>
    <div class="tiles">
        <div class="tile"><span class="subtle">Day rate</span><strong><?= money($preview['day_rate']) ?></strong></div>
        <div class="tile"><span class="subtle"><?= e($input['days']) ?> days ×</span><strong><?= money($preview['amount']) ?></strong>
            <span class="subtle"><?= e(\App\Core\Config::get('payroll.currency', 'BHD')) ?></span></div>
    </div>
<?php endif; ?>

<?php if ($canProcess): ?>
<div class="card">
    <h2 class="panel-title">Request encashment</h2>
    <form method="post" action="<?= url('payroll/encashment/save') ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">
        <div class="field"><label>Days</label>
            <input type="number" step="0.5" min="0.5" name="days" value="<?= e($input['days']) ?>" style="width:100px" required></div>
        <div class="field"><label>Pay in month</label>
            <input type="month" name="month" value="<?= e($input['month']) ?>" required></div>
        <div class="field"><label>Reason</label><input type="text" name="reason" style="width:240px"></div>
        <button type="submit">Request</button>
        <span class="subtle">Priced from the current structure on submit.</span>
    </form>
</div>
<?php endif; ?>

<div class="card" style="padding:12px 18px">
    <strong><?= e($emp['emp_code'] . ' · ' . $emp['full_name']) ?></strong>
    <span class="subtle"> — <?= e($emp['dept_name']) ?></span>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Requested</th><th>Pay month</th><th class="num">Days</th>
        <th class="num">Day rate</th><th class="num">Amount</th><th>State</th><th>Reason</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($requests as $q): $st = (int) $q['StateID']; ?>
        <tr>
            <td class="subtle"><?= date('d/m/Y', strtotime((string) $q['RequestDate'])) ?></td>
            <td><strong><?= date('M Y', strtotime((string) $q['PayrollMonth'])) ?></strong></td>
            <td class="num"><?= (float) $q['Days'] ?></td>
            <td class="num"><?= money($q['DayRate']) ?></td>
            <td class="num"><?= money($q['Amount']) ?></td>
            <td><span class="chip <?= $st === LE::PAID ? 'day_off' : ($st === LE::APPROVED ? 'present' : 'pending') ?>">
                <?= e(LE::STATE_LABELS[$st] ?? $st) ?></span></td>
            <td class="subtle"><?= e($q['Reason']) ?></td>
            <td>
                <?php if ($canApprove && $st === LE::PENDING): ?>
                <form method="post" action="<?= url('payroll/encashment/state') ?>" class="inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="encash_id" value="<?= (int) $q['EncashID'] ?>">
                    <input type="hidden" name="state" value="<?= LE::APPROVED ?>">
                    <button class="btn-ok btn-sm" type="submit">Approve</button>
                </form>
                <?php endif; ?>
                <?php if ($canProcess && $st !== LE::PAID && $st !== LE::CANCELLED): ?>
                <form method="post" action="<?= url('payroll/encashment/state') ?>" class="inline"
                      onsubmit="return confirm('Cancel this request?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="encash_id" value="<?= (int) $q['EncashID'] ?>">
                    <input type="hidden" name="state" value="<?= LE::CANCELLED ?>">
                    <button class="btn-muted btn-sm" type="submit">Cancel</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$requests): ?><tr><td colspan="8" class="center subtle">No encashment requests.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>

<?php else: ?>
    <div class="card subtle">Pick an employee.</div>
<?php endif; ?>
