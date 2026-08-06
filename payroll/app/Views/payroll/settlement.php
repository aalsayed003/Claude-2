<div class="page-head">
    <div><h1>End-of-Service Settlement</h1>
        <p class="subtle">Indemnity, leave encashment and final dues. Nothing is saved until you choose to.</p></div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/settlement') ?>" class="inline">
    <div class="field" style="min-width:300px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?>
                <option value="<?= (int) $e1['id'] ?>" <?= ($emp && (int) $emp['id'] === (int) $e1['id']) ? 'selected' : '' ?>>
                    <?= e($e1['emp_id'] . ' — ' . $e1['full_name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field"><label>Last working day</label>
        <input type="date" name="last_working_day" value="<?= e($input['last_working_day']) ?>"></div>
    <div class="field"><label>Leave days <span class="subtle">(blank = balance)</span></label>
        <input type="number" step="0.5" name="leave_days" value="<?= e($input['leave_days']) ?>" style="width:100px"></div>
    <div class="field"><label>Notice pay</label>
        <input type="number" step="0.001" name="notice_amount" value="<?= e($input['notice_amount']) ?>" style="width:110px"></div>
    <div class="field"><label>Ticket</label>
        <input type="number" step="0.001" name="ticket_amount" value="<?= e($input['ticket_amount']) ?>" style="width:110px"></div>
    <div class="field"><label>Other earnings</label>
        <input type="number" step="0.001" name="other_earnings" value="<?= e($input['other_earnings']) ?>" style="width:110px"></div>
    <div class="field"><label>Other deduction</label>
        <input type="number" step="0.001" name="other_deduction" value="<?= e($input['other_deduction']) ?>" style="width:110px"></div>
    <button type="submit">Calculate</button>
</form>
</div>

<?php if ($error): ?>
    <div class="flash flash-error"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($result): ?>
<div class="card" style="padding:12px 18px">
    <strong><?= e($result['employee']['emp_code'] . ' · ' . $result['employee']['full_name']) ?></strong>
    <span class="subtle"> — joined <?= date('d/m/Y', strtotime($result['joining_date'])) ?>,
        last day <?= date('d/m/Y', strtotime($result['last_working_day'])) ?>,
        service <?= e($result['service']['text']) ?></span>
</div>

<div class="grid">
    <div class="card">
        <h2 class="panel-title">Entitlements</h2>
        <table class="tbl"><tbody>
            <tr><td>Indemnity
                    <span class="subtle">(<?= $result['indemnity_days'] ?> days × <?= money($result['day_rate']) ?>)</span></td>
                <td class="num"><?= money($result['indemnity']) ?></td></tr>
            <tr><td>Leave encashment
                    <span class="subtle">(<?= $result['leave_days'] ?> days)</span></td>
                <td class="num"><?= money($result['leave_encash']) ?></td></tr>
            <tr><td>Notice pay</td><td class="num"><?= money($result['notice']) ?></td></tr>
            <tr><td>Ticket</td><td class="num"><?= money($result['ticket']) ?></td></tr>
            <tr><td>Other earnings</td><td class="num"><?= money($result['other_earnings']) ?></td></tr>
            <tr><td><strong>Total</strong></td><td class="num"><strong><?= money($result['total_earnings']) ?></strong></td></tr>
        </tbody></table>
    </div>

    <div class="card">
        <h2 class="panel-title">Recoveries</h2>
        <table class="tbl"><tbody>
            <tr><td>Outstanding loans</td><td class="num"><?= money($result['loan_recovery']) ?></td></tr>
            <tr><td>Other deduction</td><td class="num"><?= money($result['other_deduction']) ?></td></tr>
            <tr><td><strong>Total</strong></td><td class="num"><strong><?= money($result['total_deduction']) ?></strong></td></tr>
        </tbody></table>
        <table class="tbl" style="margin-top:12px"><tbody>
            <tr><td>Last basic / gross</td><td class="num"><?= money($result['basic']) ?> / <?= money($result['gross']) ?></td></tr>
            <tr><td>Day rate used</td><td class="num"><?= money($result['day_rate']) ?></td></tr>
        </tbody></table>
    </div>
</div>

<div class="tiles">
    <div class="tile"><span class="subtle">Net settlement</span><strong><?= money($result['net']) ?></strong>
        <span class="subtle"><?= e(\App\Core\Config::get('payroll.currency', 'BHD')) ?></span></div>
</div>

<?php if ($canProcess): ?>
<div class="card">
    <form method="post" action="<?= url('payroll/settlement/save') ?>" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= (int) $emp['id'] ?>">
        <input type="hidden" name="last_working_day" value="<?= e($input['last_working_day']) ?>">
        <input type="hidden" name="leave_days" value="<?= e($input['leave_days']) ?>">
        <input type="hidden" name="notice_amount" value="<?= e($input['notice_amount']) ?>">
        <input type="hidden" name="ticket_amount" value="<?= e($input['ticket_amount']) ?>">
        <input type="hidden" name="other_earnings" value="<?= e($input['other_earnings']) ?>">
        <input type="hidden" name="other_deduction" value="<?= e($input['other_deduction']) ?>">
        <div class="field"><label>Reason</label>
            <select name="reason_id">
                <?php foreach ($reasons as $k => $v): ?>
                    <option value="<?= $k ?>" <?= (int) $input['reason_id'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Remarks</label><input type="text" name="remarks" style="width:260px"></div>
        <button type="submit">Save as draft settlement</button>
    </form>
    <p class="muted-note">
        Confirm the indemnity basis for expatriate staff against the current SIO end-of-service
        scheme before paying — this figure is the gross entitlement and does not net off anything
        already remitted to SIO.
    </p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($saved): ?>
<div class="card">
    <h2 class="panel-title">Saved settlements</h2>
    <table class="tbl">
        <thead><tr><th>Last day</th><th class="num">Service (yrs)</th><th class="num">Indemnity</th>
            <th class="num">Leave</th><th class="num">Net</th><th>State</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($saved as $s): ?>
            <tr><td><?= date('d/m/Y', strtotime((string) $s['LastWorkingDay'])) ?></td>
                <td class="num"><?= round((float) $s['ServiceYears'], 2) ?></td>
                <td class="num"><?= money($s['IndemnityAmount']) ?></td>
                <td class="num"><?= money($s['LeaveEncashment']) ?></td>
                <td class="num"><strong><?= money($s['NetSettlement']) ?></strong></td>
                <td><span class="chip"><?= [1 => 'Draft', 2 => 'Approved', 3 => 'Paid', 9 => 'Cancelled'][(int) $s['StateID']] ?? '' ?></span></td>
                <td class="subtle"><?= e($s['CreatedBy']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
