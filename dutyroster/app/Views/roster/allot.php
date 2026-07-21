<div class="page-head">
    <div><h1>Duty Roster — Allot Shift</h1><p class="subtle">Assign a shift to each day of the period.</p></div>
    <a class="btn btn-muted" href="<?= url('roster?period='.$period) ?>">← Roster</a>
</div>

<div class="card">
<form method="get" action="<?= url('roster/allot') ?>" class="inline">
    <div class="field" style="min-width:280px"><label>Employee</label>
        <select name="employee_id" onchange="this.form.submit()">
            <option value="">Select employee…</option>
            <?php foreach ($employees as $emp1): ?>
                <option value="<?= $emp1['id'] ?>" <?= ($emp && $emp['id']==$emp1['id'])?'selected':'' ?>>
                    <?= e($emp1['emp_id'].' — '.$emp1['full_name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field"><label>Month</label><input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()"></div>
</form>
</div>

<?php if ($emp): ?>
<form method="post" action="<?= url('roster/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
    <input type="hidden" name="period" value="<?= e($period) ?>">
    <div class="card">
        <div class="inline" style="justify-content:space-between">
            <div>
                <strong><?= e($emp['emp_id'].' · '.$emp['full_name']) ?></strong>
                <span class="subtle"> — <?= e(period_label($period)) ?></span>
            </div>
            <div class="inline">
                <label class="checkbox subtle">Quick fill:
                    <select id="quickShift">
                        <option value="">shift…</option>
                        <?php foreach ($shifts as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['code']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <button type="button" class="btn btn-sm btn-muted" onclick="fillAll()">Apply to all</button>
                <span class="subtle">Scheduled: <strong id="schedHrs"><?= number_format((float)$scheduled_hours,1) ?></strong> hrs</span>
            </div>
        </div>
    </div>

    <div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th>Day</th><th>Date</th><th>Shift</th><th>First In</th><th>First Out</th><th>Second In</th><th>Second Out</th><th class="num">Hrs</th></tr></thead>
        <tbody>
        <?php foreach ($days as $d):
            $a = $assigned[$d] ?? null;
            $dow = (int)date('w', strtotime($d));
            $weekend = in_array($dow, [5,6], true);
        ?>
            <tr class="<?= $weekend?'row-day_off':'' ?>">
                <td><strong><?= date('D', strtotime($d)) ?></strong></td>
                <td><?= date('d M', strtotime($d)) ?></td>
                <td>
                    <select name="shift[<?= $d ?>]" class="shiftSel" onchange="recalc()">
                        <option value="">—</option>
                        <?php foreach ($shifts as $s): ?>
                            <option value="<?= $s['id'] ?>"
                                data-h="<?= (float)$s['total_hours'] ?>"
                                data-fi="<?= e(substr((string)$s['first_in'],0,5)) ?>"
                                data-fo="<?= e(substr((string)$s['first_out'],0,5)) ?>"
                                data-si="<?= e(substr((string)$s['second_in'],0,5)) ?>"
                                data-so="<?= e(substr((string)$s['second_out'],0,5)) ?>"
                                <?= ($a && $a['shift_id']==$s['id'])?'selected':'' ?>>
                                <?= e($s['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="fi"><?= e(substr((string)($a['first_in']??''),0,5)) ?></td>
                <td class="fo"><?= e(substr((string)($a['first_out']??''),0,5)) ?></td>
                <td class="si"><?= e(substr((string)($a['second_in']??''),0,5)) ?></td>
                <td class="so"><?= e(substr((string)($a['second_out']??''),0,5)) ?></td>
                <td class="num hh"><?= $a ? number_format((float)$a['total_hours'],1) : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div style="margin-top:14px" class="actions">
        <button type="submit">Save Roster</button>
        <a class="btn btn-muted" href="<?= url('roster/submit?period='.$period) ?>">Submit for Approval →</a>
    </div>
</form>

<script>
function recalc(){
  let total=0;
  document.querySelectorAll('tr').forEach(tr=>{
    const sel=tr.querySelector('.shiftSel'); if(!sel) return;
    const o=sel.options[sel.selectedIndex];
    const h=o?parseFloat(o.dataset.h||0):0;
    tr.querySelector('.fi').textContent=o?o.dataset.fi||'':'';
    tr.querySelector('.fo').textContent=o?o.dataset.fo||'':'';
    tr.querySelector('.si').textContent=o?o.dataset.si||'':'';
    tr.querySelector('.so').textContent=o?o.dataset.so||'':'';
    tr.querySelector('.hh').textContent=(o&&sel.value)?h.toFixed(1):'';
    total+=isNaN(h)?0:h;
  });
  document.getElementById('schedHrs').textContent=total.toFixed(1);
}
function fillAll(){
  const v=document.getElementById('quickShift').value; if(!v) return;
  document.querySelectorAll('.shiftSel').forEach(s=>s.value=v);
  recalc();
}
</script>
<?php endif; ?>
