<?php
function tm($v){ return $v ? date('h:i a', strtotime($v)) : ''; }
?>
<div class="page-head">
    <div><h1>View Attendance</h1><p class="subtle">Actual vs scheduled timings per day.</p></div>
    <?php if (\App\Core\Auth::atLeast('dept_head')): ?>
    <form method="post" action="<?= url('attendance/rebuild') ?>" onsubmit="return confirm('Recompute attendance for the current period?')">
        <?= csrf_field() ?><button class="btn btn-muted btn-sm">Recompute period</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
<form method="get" action="<?= url('attendance') ?>" class="inline">
    <?php if ($canPickAnyone): ?>
    <div class="field" style="min-width:280px"><label>Employee</label>
        <select name="employee_id">
            <option value="">Select…</option>
            <?php foreach ($employees as $e1): ?>
                <option value="<?= $e1['id'] ?>" <?= ($emp && $emp['id']==$e1['id'])?'selected':'' ?>>
                    <?= e($e1['emp_id'].' — '.$e1['full_name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <?php endif; ?>
    <div class="field"><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <button type="submit">Show</button>
</form>
</div>

<?php if ($emp): ?>
<div class="card" style="padding:12px 18px">
    <strong><?= e($emp['emp_id'].' · '.$emp['full_name']) ?></strong>
    <div class="legend">
        <span class="leg"><span class="sw no_punch"></span> No Punching</span>
        <span class="leg"><span class="sw holiday"></span> Holiday</span>
        <span class="leg"><span class="sw day_off"></span> Day Off</span>
        <span class="leg"><span class="sw leave"></span> Leave</span>
    </div>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead>
        <tr>
            <th rowspan="2">Date</th>
            <th colspan="4" class="center">Actual Timings</th>
            <th colspan="4" class="center">Scheduled Timings</th>
            <th rowspan="2" class="num">Late In</th><th rowspan="2" class="num">Early Out</th><th rowspan="2">Status</th>
        </tr>
        <tr>
            <th>First In</th><th>First Out</th><th>Second In</th><th>Second Out</th>
            <th>First In</th><th>First Out</th><th>Second In</th><th>Second Out</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): $cls='row-'.$r['status']; ?>
        <tr class="<?= in_array($r['status'],['day_off','holiday','leave','absent','no_punch'])?$cls:'' ?>">
            <td><strong><?= date('d/m/Y', strtotime($r['work_date'])) ?></strong> <span class="subtle"><?= date('D', strtotime($r['work_date'])) ?></span></td>
            <?php if (in_array($r['status'],['day_off','holiday','leave'])): ?>
                <td colspan="4" class="center"><span class="chip <?= $r['status'] ?>"><?= strtoupper(str_replace('_',' ',$r['status'])) ?></span></td>
            <?php else: ?>
                <td><?= tm($r['act_first_in']) ?></td>
                <td><?= tm($r['act_first_out']) ?></td>
                <td><?= tm($r['act_second_in']) ?></td>
                <td><?= tm($r['act_second_out']) ?></td>
            <?php endif; ?>
            <td><?= e(substr((string)$r['sch_first_in'],0,5)) ?></td>
            <td><?= e(substr((string)$r['sch_first_out'],0,5)) ?></td>
            <td><?= e(substr((string)$r['sch_second_in'],0,5)) ?></td>
            <td><?= e(substr((string)$r['sch_second_out'],0,5)) ?></td>
            <td class="num <?= $r['late_in_min']?'late':'' ?>"><?= $r['late_in_min']?:'' ?></td>
            <td class="num <?= $r['early_out_min']?'late':'' ?>"><?= $r['early_out_min']?:'' ?></td>
            <td><span class="chip <?= $r['status'] ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span>
                <?= $r['is_odd_punch']?'<span class="chip pending" title="Odd number of punches">odd</span>':'' ?>
                <?= !empty($r['corrected'])?'<span class="chip applied" title="Approved correction applied">corrected</span>':'' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="12" class="center subtle">No attendance rows. Pick an employee and date range, or recompute the period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<?php elseif (!$canPickAnyone): ?>
    <div class="card subtle">Your account is not linked to an employee record yet. Ask an administrator to link it.</div>
<?php endif; ?>
