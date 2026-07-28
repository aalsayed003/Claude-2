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
                <button type="button" class="btn btn-sm btn-muted" onclick="applyToSelected()"
                        title="Applies to the days checked below; if none are checked, applies to all days">
                    Apply to selected</button>
                <?php if (!empty($last_by_dom)): ?>
                <button type="button" class="btn btn-sm btn-muted" onclick="rollForward()"
                        title="Fill the empty days from last month's roster (same dates); then edit and save">
                    ↩ Roll forward last month</button>
                <?php endif; ?>
                <span class="subtle">Scheduled: <strong id="schedHrs"><?= number_format((float)$scheduled_hours,1) ?></strong> hrs</span>
            </div>
        </div>
    </div>

    <div class="tbl-wrap">
    <table class="tbl">
        <thead><tr><th><input type="checkbox" id="selAll" onclick="toggleAll(this)" title="Select / deselect all"></th><th>Day</th><th>Date</th><th>Shift</th><th>First In</th><th>First Out</th><th>Second In</th><th>Second Out</th><th class="num">Hrs</th></tr></thead>
        <tbody>
        <?php foreach ($days as $d):
            $a = $assigned[$d] ?? null;
            $dow = (int)date('w', strtotime($d));
            $weekend = in_array($dow, [5,6], true);
        ?>
            <tr class="<?= $weekend?'row-day_off':'' ?>">
                <td><input type="checkbox" class="rowChk"></td>
                <td><strong><?= date('D', strtotime($d)) ?></strong></td>
                <td><?= date('d M', strtotime($d)) ?></td>
                <td>
                    <select name="shift[<?= $d ?>]" class="shiftSel" data-dom="<?= (int)date('j', strtotime($d)) ?>" onchange="recalc()">
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
        <button type="submit">Save &amp; Submit for Approval</button>
        <a class="btn btn-muted" href="<?= url('roster/submit?period='.$period) ?>" title="Use this to submit a whole department/section at once, or re-submit after a rejection">Submit a Department Manually →</a>
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
function toggleAll(cb){
  document.querySelectorAll('.rowChk').forEach(c=>c.checked=cb.checked);
}
function applyToSelected(){
  const v=document.getElementById('quickShift').value; if(!v) return;
  const checked=[...document.querySelectorAll('.rowChk:checked')];
  const targets = checked.length ? checked : [...document.querySelectorAll('.rowChk')];
  targets.forEach(c=>{
    const sel=c.closest('tr').querySelector('.shiftSel');
    if(sel) sel.value=v;
  });
  recalc();
}
// Roll forward: fill only the EMPTY days from last month's roster (same dates).
// Nothing is saved until you click Save, so you can review/edit first.
const LAST_BY_DOM = <?= json_encode((object)($last_by_dom ?? []), JSON_UNESCAPED_UNICODE) ?>;
function rollForward(){
  let filled=0;
  document.querySelectorAll('.shiftSel').forEach(sel=>{
    if(sel.value) return;                       // keep this month's existing choices
    const sid = LAST_BY_DOM[sel.dataset.dom];
    if(sid && [...sel.options].some(o=>o.value==sid)){ sel.value=String(sid); filled++; }
  });
  recalc();
  if(!filled) alert("Nothing to roll forward — last month has no roster for the empty days (or those shifts are now hidden).");
}
</script>
<?php endif; ?>