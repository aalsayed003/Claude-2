<?php $import = $import ?? null; ?>
<div class="page-head">
    <div><h1>Duty Roster</h1><p class="subtle">Assign shifts per employee, or import a whole department from Excel.</p></div>
</div>

<?php if ($total_employees > 0): ?>
<div class="card" style="padding:10px 16px;margin-bottom:14px;border-left:4px solid <?= $not_scheduled ? '#f9a825' : '#2e7d32' ?>;background:<?= $not_scheduled ? '#fffdf3' : '#f2fbf3' ?>">
    <?php if ($not_scheduled): ?>
        <strong><?= (int) $not_scheduled ?></strong> of <strong><?= (int) $total_employees ?></strong> employees
        still have <strong>no roster</strong> for <?= e(period_label($period)) ?>.
    <?php else: ?>
        <strong>All <?= (int) $total_employees ?> employees</strong> have a roster for <?= e(period_label($period)) ?>.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="tabs" style="display:flex;gap:6px;margin:0 0 0;border-bottom:1px solid #e2e6ee">
    <?php $tl = ['roster' => 'Roster', 'bulk' => 'Bulk Excel Import']; foreach ($tl as $key => $label): ?>
        <button type="button" class="tab-btn<?= $tab===$key?' active':'' ?>" data-tab="<?= $key ?>"
            style="padding:10px 16px;border:none;background:none;cursor:pointer;font-weight:600;
                   border-bottom:3px solid <?= $tab===$key?'var(--brand,#1a3a63)':'transparent' ?>;
                   color:<?= $tab===$key?'inherit':'#7a8296' ?>"><?= e($label) ?></button>
    <?php endforeach; ?>
</div>

<!-- ===================== TAB: ROSTER (list + Allot Shift) ===================== -->
<div class="tab-panel" data-panel="roster" style="<?= $tab==='roster'?'':'display:none;' ?>margin-top:14px">

<div class="card">
<form method="get" action="<?= url('roster') ?>" class="inline" id="rosterFilter">
    <input type="hidden" name="tab" id="rosterTabField" value="<?= e($tab) ?>">
    <div class="field" style="min-width:280px"><label>Employee</label>
        <select name="employee_id" onchange="this.form.submit()">
            <option value="">All employees…</option>
            <?php foreach ($employees as $emp1): ?>
                <option value="<?= $emp1['id'] ?>" <?= ($emp && $emp['id']==$emp1['id'])?'selected':'' ?>>
                    <?= e($emp1['emp_id'].' — '.$emp1['full_name']) ?></option>
            <?php endforeach; ?>
        </select></div>
    <div class="field"><label>Month</label><input type="month" name="period" value="<?= e($period) ?>" onchange="this.form.submit()"></div>
</form>
</div>

<?php if ($emp): ?>
<!-- --- Allot Shift grid for the selected employee --- -->
<form method="post" action="<?= url('roster/save') ?>" style="margin-top:14px">
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
        <a class="btn btn-muted" href="<?= url('roster?employee_id='.$emp['id'].'&period='.$period) ?>">Clear selection</a>
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
const LAST_BY_DOM = <?= json_encode((object)($last_by_dom ?? []), JSON_UNESCAPED_UNICODE) ?>;
function rollForward(){
  let filled=0;
  document.querySelectorAll('.shiftSel').forEach(sel=>{
    if(sel.value) return;
    const sid = LAST_BY_DOM[sel.dataset.dom];
    if(sid && [...sel.options].some(o=>o.value==sid)){ sel.value=String(sid); filled++; }
  });
  recalc();
  if(!filled) alert("Nothing to roll forward — last month has no roster for the empty days (or those shifts are now hidden).");
}
</script>
<?php endif; ?>

<!-- --- Employee list (assigned-day counts), always visible for switching --- -->
<div class="card" style="margin-top:14px">
    <h3 style="margin-top:0">All Employees — <?= e(period_label($period)) ?></h3>
    <div class="tbl-wrap" style="max-height:420px">
    <table class="tbl">
        <thead><tr><th>Emp ID</th><th>Name</th><th class="num">Days Assigned</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['emp_id']) ?></strong></td>
                <td><?= e($r['full_name']) ?></td>
                <td class="num"><?= (int)$r['assigned_days'] ?></td>
                <td><a class="btn btn-sm" href="<?= url('roster?tab=roster&employee_id='.$r['id'].'&period='.$period) ?>">Allot Shift</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
</div>

<!-- ===================== TAB: BULK EXCEL IMPORT ===================== -->
<div class="tab-panel" data-panel="bulk" style="<?= $tab==='bulk'?'':'display:none;' ?>margin-top:14px">

<?php if ($import): ?>
    <?php if (!empty($import['ok']) && !empty($import['summary'])): $s = $import['summary']; ?>
        <div class="card" style="border-left:4px solid #2e7d32;background:#f2fbf3">
            <strong>✓ Roster imported.</strong>
            <?= (int) $s['employees'] ?> employee<?= $s['employees'] == 1 ? '' : 's' ?> and
            <?= (int) $s['assignments'] ?> day-assignment<?= $s['assignments'] == 1 ? '' : 's' ?>
            saved for <strong><?= e($import['deptName']) ?></strong> — <?= e(period_label($import['period'])) ?>.
            <?= !empty($s['submitted'])
                ? 'A new approval request was raised.'
                : 'An approval request for this month was already open, so it was left in place.' ?>
        </div>
    <?php else: ?>
        <div class="card" style="border-left:4px solid #c62828;background:#fdf3f3">
            <strong>✗ Nothing was imported.</strong> Fix the problems below in your Excel file and upload it again —
            the roster is only saved when every row is clean.
            <ul style="margin:10px 0 0 18px">
                <?php foreach ($import['errors'] as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if (!empty($import['warnings'])): ?>
        <div class="card" style="border-left:4px solid #f9a825;background:#fffdf3">
            <strong>Notes:</strong>
            <ul style="margin:10px 0 0 18px">
                <?php foreach ($import['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start">

    <div class="card">
        <h3 style="margin-top:0">1. Download template</h3>
        <p class="subtle">Pre-filled with the team's employees and a shift dropdown per day.</p>
        <form method="get" action="<?= url('roster/template') ?>">
            <div class="field"><label>Department *</label>
                <select name="department_id" required>
                    <option value="">Select…</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label>Month *</label>
                <input type="month" name="period" value="<?= e($period) ?>" required></div>
            <label class="checkbox subtle" style="display:flex;gap:8px;align-items:center;margin:2px 0 12px">
                <input type="checkbox" name="prefill" value="1">
                Roll forward from last month
            </label>
            <button type="submit">⬇ Download template</button>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-top:0">2. Upload filled roster</h3>
        <p class="subtle">Blank days are left unchanged; unrecognised cells are skipped with a note.</p>
        <form method="post" action="<?= url('roster/import') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field"><label>Roster file (.xlsx)</label>
                <input type="file" name="file" accept=".xlsx,.csv" required></div>
            <div class="field"><label>Department</label>
                <select name="department_id">
                    <option value="">Auto-detect from file</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label>Month</label>
                <input type="month" name="period" value="<?= e($period) ?>"></div>
            <button type="submit">⬆ Upload &amp; submit</button>
        </form>
    </div>

</div>

<p style="margin:18px 0 0">
    <a href="#" id="manualSubmitToggle" onclick="document.getElementById('manualSubmitBox').style.display='block';this.style.display='none';return false"
       class="subtle" style="text-decoration:underline">Already have a prepared roster, or re-submitting after a rejection? Submit a department directly →</a>
</p>
<div class="card" id="manualSubmitBox" style="max-width:420px;display:none;margin-top:10px">
    <form method="post" action="<?= url('roster/submit') ?>">
        <?= csrf_field() ?>
        <div class="field"><label>Month</label><input type="month" name="period" value="<?= e($period) ?>" required></div>
        <div class="field"><label>Department *</label>
            <select name="department_id" required>
                <option value="">Select…</option>
                <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
            </select></div>
        <?php if (!empty($sections)): ?>
        <div class="field"><label>Section</label>
            <select name="section_id">
                <option value="">All / not applicable</option>
                <?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></div>
        <?php endif; ?>
        <button type="submit">Submit for Approval</button>
    </form>
</div>
</div>

<script>
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click', function(){
    const key = this.dataset.tab;
    document.querySelectorAll('.tab-btn').forEach(b=>{
      b.classList.toggle('active', b===this);
      b.style.borderBottomColor = (b===this) ? 'var(--brand,#1a3a63)' : 'transparent';
      b.style.color = (b===this) ? 'inherit' : '#7a8296';
    });
    document.querySelectorAll('.tab-panel').forEach(p=> p.style.display = (p.dataset.panel===key) ? '' : 'none');
    document.getElementById('rosterTabField').value = key;
  });
});
</script>
