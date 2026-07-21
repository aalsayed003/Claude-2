<div class="page-head"><div><h1>Overtime</h1>
    <p class="subtle">Early-in / late-out overtime for period
        <strong><?= e(period_label($period)) ?></strong>.</p></div></div>

<div class="card">
<form method="get" action="<?= url('overtime') ?>" class="inline">
    <?php if ($employees): ?>
    <div class="field" style="min-width:280px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?><option value="<?= $e1['id'] ?>" <?= ($emp&&$emp['id']==$e1['id'])?'selected':'' ?>><?= e($e1['emp_id'].' — '.$e1['full_name']) ?></option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="field"><label>Month</label><input type="month" name="period" value="<?= e($period) ?>"></div>
    <button type="submit">Show</button>
</form>
</div>

<?php if ($emp): ?>
<div class="grid" style="grid-template-columns:1fr 1fr">
<div class="card">
    <h2 style="margin-top:0">Eligible OT (from punches)</h2>
    <table class="tbl"><thead><tr><th>Date</th><th class="num">Early Punch-In (min)</th><th class="num">Late Punch-Out (min)</th></tr></thead><tbody>
        <?php foreach ($eligible as $x): ?>
            <tr><td><?= date('d M Y', strtotime($x['work_date'])) ?></td>
                <td class="num"><?= $x['ot_early_min']?:'' ?></td><td class="num"><?= $x['ot_late_min']?:'' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$eligible): ?><tr><td colspan="3" class="subtle center">No eligible OT in this period.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<div class="card">
    <h2 style="margin-top:0">New Overtime Request</h2>
    <form method="post" action="<?= url('overtime/save') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
        <div class="inline">
            <div class="field" style="flex:1"><label>OT Date *</label><input type="date" name="ot_date" required></div>
            <div class="field" style="flex:1"><label>Day Type</label>
                <select name="day_type"><option value="working">Working Day</option><option value="off">Off Day</option></select></div>
        </div>
        <div class="inline">
            <div class="field"><label>From</label><input type="time" name="from_time"></div>
            <div class="field"><label>To</label><input type="time" name="to_time"></div>
            <div class="field"><label>Type</label>
                <select name="ot_type"><option value="in">OT In-time</option><option value="out">OT Out-time</option></select></div>
        </div>
        <label class="checkbox"><input type="checkbox" name="is_split_day" value="1"> Split day</label>
        <div class="field"><label>Reason</label><input name="reason"></div>
        <div class="field"><label>Remark</label><textarea name="remark" rows="2"></textarea></div>
        <button type="submit">Submit OT</button>
    </form>
</div>
</div>
<div class="card">
    <h2 style="margin-top:0">Overtime Request(s)</h2>
    <table class="tbl"><thead><tr><th>Date</th><th>Day</th><th class="num">Minutes</th><th>Reason</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($requests as $r): ?>
            <tr><td><?= date('d M Y', strtotime($r['ot_date'])) ?></td><td><?= e(ucfirst($r['day_type'])) ?></td>
                <td class="num"><?= $r['total_minutes'] ?></td><td><?= e($r['reason']) ?></td>
                <td><span class="chip <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="5" class="subtle center">No requests.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php endif; ?>
