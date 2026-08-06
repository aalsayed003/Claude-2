<div class="page-head"><div><h1>Change Schedule</h1>
    <p class="subtle">Swap a day's assigned shift; routed for approval.</p></div></div>

<div class="card">
<form method="get" action="<?= url('schedule-change') ?>" class="inline">
    <?php if ($employees): ?>
    <div class="field" style="min-width:280px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?><option value="<?= $e1['id'] ?>" <?= ($emp&&$emp['id']==$e1['id'])?'selected':'' ?>><?= e($e1['emp_id'].' — '.$e1['full_name']) ?></option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <button type="submit">Load</button>
</form>
</div>

<?php if ($emp): ?>
<div class="grid" style="grid-template-columns:1fr 1fr">
<div class="card">
    <h2 style="margin-top:0">New Schedule Change</h2>
    <form method="post" action="<?= url('schedule-change/save') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
        <div class="field"><label>Schedule Day *</label><input type="date" name="work_date" required></div>
        <div class="inline">
            <div class="field" style="flex:1"><label>Old Shift</label>
                <select name="old_shift_id"><option value="">—</option>
                    <?php foreach ($shifts as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['code']) ?></option><?php endforeach; ?></select></div>
            <div class="field" style="flex:1"><label>New Shift</label>
                <select name="new_shift_id"><option value="">—</option>
                    <?php foreach ($shifts as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['code']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="inline">
            <div class="field" style="flex:1"><label>Change Against Date</label><input type="date" name="change_against_date"></div>
            <div class="field" style="flex:1"><label>Claim Time</label><input name="claim_time" placeholder="e.g. 8h"></div>
        </div>
        <button type="submit">Submit Request</button>
    </form>
</div>
<div class="card">
    <h2 style="margin-top:0">Request(s) List</h2>
    <table class="tbl"><thead><tr><th>Day</th><th>Old</th><th>New</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($requests as $r): ?>
            <tr><td><?= date('d M', strtotime($r['work_date'])) ?></td><td><?= e($r['old_code']) ?></td>
                <td><?= e($r['new_code']) ?></td><td><span class="chip <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="4" class="subtle center">No requests.</td></tr><?php endif; ?>
    </tbody></table>
</div>
</div>
<?php endif; ?>
