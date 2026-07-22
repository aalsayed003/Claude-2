<?php function tm2($v){ return $v?date('h:i a',strtotime($v)):''; } ?>
<div class="page-head"><div><h1>Attendance Correction</h1>
    <p class="subtle">Request a punch correction within the cutoff window
        <strong><?= date('d M Y',strtotime($cutFrom)) ?> → <?= date('d M Y',strtotime($cutTo)) ?></strong>.</p></div>
</div>

<div class="card">
<form method="get" action="<?= url('correction') ?>" class="inline">
    <?php if ($employees): ?>
    <div class="field" style="min-width:280px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?><option value="<?= $e1['id'] ?>" <?= ($emp&&$emp['id']==$e1['id'])?'selected':'' ?>><?= e($e1['emp_id'].' — '.$e1['full_name']) ?></option><?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="field"><label>Month</label><input type="month" name="period" value="<?= e($period) ?>"></div>
    <button type="submit">Show Attendance</button>
</form>
</div>

<?php if ($emp): ?>
<div class="grid" style="grid-template-columns:1.4fr 1fr">
    <div class="card">
        <h2 style="margin-top:0">Employee Attendance</h2>
        <div class="tbl-wrap" style="max-height:420px">
        <table class="tbl"><thead><tr><th>Date</th><th>First In</th><th>First Out</th><th>Second In</th><th>Second Out</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($attendance as $a): ?>
                <tr class="<?= in_array($a['status'],['day_off','holiday','leave','absent'])?'row-'.$a['status']:'' ?>">
                    <td><?= date('d M', strtotime($a['work_date'])) ?></td>
                    <td><?= tm2($a['act_first_in']) ?></td><td><?= tm2($a['act_first_out']) ?></td>
                    <td><?= tm2($a['act_second_in']) ?></td><td><?= tm2($a['act_second_out']) ?></td>
                    <td><span class="chip <?= $a['status'] ?>"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$attendance): ?><tr><td colspan="6" class="subtle center">No rows.</td></tr><?php endif; ?>
        </tbody></table>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0">New Correction</h2>
        <form method="post" action="<?= url('correction/save') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <div class="field"><label>Date *</label><input type="date" name="work_date" min="<?= e($cutFrom) ?>" max="<?= e($cutTo) ?>" required></div>
            <div class="inline">
                <div class="field"><label>First In</label><input type="time" name="first_in"></div>
                <div class="field"><label>First Out</label><input type="time" name="first_out"></div>
            </div>
            <div class="inline">
                <div class="field"><label>Second In</label><input type="time" name="second_in"></div>
                <div class="field"><label>Second Out</label><input type="time" name="second_out"></div>
            </div>
            <div class="field"><label>Reason</label>
                <select name="reason">
                    <?php if (!empty($reasons)): ?>
                        <?php foreach ($reasons as $rn): ?>
                            <option value="<?= e($rn['id']) ?>"><?= e($rn['name']) ?></option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option>Forgot to punch</option><option>Official duty</option>
                        <option>Appointment</option><option>Device error</option><option>Others</option>
                    <?php endif; ?>
                </select></div>
            <div class="field"><label>Remarks</label><textarea name="remarks" rows="2"></textarea></div>
            <button type="submit">Submit Correction</button>
        </form>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0">Recent Requests</h2>
    <table class="tbl"><thead><tr><th>#</th><th>Requested</th><th>Lines</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($requests as $r): ?>
            <tr><td><?= $r['id'] ?></td><td class="subtle"><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
                <td><?= $r['lines'] ?></td><td><span class="chip <?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="4" class="subtle center">No requests yet.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php endif; ?>
