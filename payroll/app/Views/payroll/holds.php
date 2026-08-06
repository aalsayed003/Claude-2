<?php use App\Repositories\SalaryHoldRepository as SH; ?>
<div class="page-head">
    <div><h1>Salary Hold &amp; Release</h1>
        <p class="subtle">A held month still calculates onto the register but is left out of the bank file. Releasing pays the held net as an arrear in a later month.</p></div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/holds') ?>" class="inline">
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
    <h2 class="panel-title">Hold a month</h2>
    <form method="post" action="<?= url('payroll/holds/hold') ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">
        <div class="field"><label>Payroll month</label>
            <input type="month" name="hold_month" value="<?= e($suggest) ?>" required></div>
        <div class="field"><label>Memo #</label><input type="text" name="memo" style="width:110px"></div>
        <div class="field"><label>Reason</label><input type="text" name="reason" style="width:260px"></div>
        <button type="submit">Hold salary</button>
    </form>
</div>
<?php endif; ?>

<div class="card" style="padding:12px 18px">
    <strong><?= e($emp['emp_code'] . ' · ' . $emp['full_name']) ?></strong>
    <span class="subtle"> — <?= e($emp['dept_name']) ?></span>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Held month</th><th class="num">Held net</th><th>State</th>
        <th>Released into</th><th>Memo</th><th>Reason</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($holds as $h): $st = (int) $h['StateID']; ?>
        <tr>
            <td><strong><?= date('F Y', strtotime((string) $h['HoldMonth'])) ?></strong></td>
            <td class="num"><?= $h['HeldNet'] !== null ? money($h['HeldNet']) : '<span class="subtle">pending lock</span>' ?></td>
            <td><span class="chip <?= $st === SH::HELD ? 'pending' : ($st === SH::RELEASED ? 'present' : 'day_off') ?>">
                <?= e(SH::STATE_LABELS[$st] ?? $st) ?></span></td>
            <td class="subtle"><?= $h['ReleaseMonth'] ? date('M Y', strtotime((string) $h['ReleaseMonth'])) : '' ?></td>
            <td class="subtle"><?= e($h['HoldMemo']) ?></td>
            <td class="subtle"><?= e($h['HoldReason']) ?></td>
            <td>
                <?php if ($canProcess && $st === SH::HELD): ?>
                <form method="post" action="<?= url('payroll/holds/release') ?>" class="inline"
                      onsubmit="return confirm('Release the held salary into the chosen month?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hold_id" value="<?= (int) $h['HoldID'] ?>">
                    <input type="month" name="release_month" value="<?= e(date('Y-m')) ?>" style="width:130px">
                    <button class="btn-ok btn-sm" type="submit"
                        <?= $h['HeldNet'] === null ? 'disabled title="Lock the held month first"' : '' ?>>Release</button>
                </form>
                <form method="post" action="<?= url('payroll/holds/cancel') ?>" class="inline"
                      onsubmit="return confirm('Cancel this hold?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="hold_id" value="<?= (int) $h['HoldID'] ?>">
                    <button class="btn-muted btn-sm" type="submit">Cancel</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$holds): ?><tr><td colspan="7" class="center subtle">No holds for this employee.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>

<?php else: ?>

<div class="card">
    <h2 class="panel-title">Currently held — <?= count($heldList) ?></h2>
    <?php if ($heldList): ?>
    <table class="tbl">
        <thead><tr><th>Code</th><th>Employee</th><th>Held month</th><th class="num">Held net</th><th>Memo</th></tr></thead>
        <tbody>
        <?php foreach ($heldList as $h): ?>
            <tr><td><?= e($h['emp_code']) ?></td>
                <td><a href="<?= url('payroll/holds?employee_id=' . (int) $h['EmployeeID']) ?>"><?= e($h['emp_name']) ?></a></td>
                <td><?= date('F Y', strtotime((string) $h['HoldMonth'])) ?></td>
                <td class="num"><?= $h['HeldNet'] !== null ? money($h['HeldNet']) : '<span class="subtle">pending</span>' ?></td>
                <td class="subtle"><?= e($h['HoldMemo']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <p class="subtle">No salaries are currently held. Pick an employee above to hold one.</p>
    <?php endif; ?>
</div>

<?php endif; ?>
