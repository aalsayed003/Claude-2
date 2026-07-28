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
        <p class="subtle" style="margin-top:-6px">Pick a day, then tick which punch is wrong. On approval it is reset to the
            <strong>scheduled roster time</strong> for that day — you don't enter a time.</p>
        <form method="post" action="<?= url('correction/save') ?>" id="corrForm">
            <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <div class="field"><label>Date *</label>
                <input type="date" id="corrDate" name="work_date" min="<?= e($cutFrom) ?>" max="<?= e($cutTo) ?>" required onchange="onCorrDate()"></div>

            <div id="schedNote" class="subtle" style="margin:-4px 0 10px">Select a day to see its rostered shift.</div>

            <fieldset id="punchSet" style="border:1px solid #e2e6ee;border-radius:8px;padding:10px 12px;margin:0 0 12px" disabled>
                <legend class="subtle" style="padding:0 6px">Which punch needs correcting?</legend>
                <label class="pchk" data-f="first_in"   style="display:flex;gap:8px;align-items:center;padding:4px 0">
                    <input type="checkbox" name="fix_first_in"><span>First In</span><span class="sc subtle"></span></label>
                <label class="pchk" data-f="first_out"  style="display:flex;gap:8px;align-items:center;padding:4px 0">
                    <input type="checkbox" name="fix_first_out"><span>First Out</span><span class="sc subtle"></span></label>
                <label class="pchk secOnly" data-f="second_in"  style="display:flex;gap:8px;align-items:center;padding:4px 0">
                    <input type="checkbox" name="fix_second_in"><span>Second In</span><span class="sc subtle"></span></label>
                <label class="pchk secOnly" data-f="second_out" style="display:flex;gap:8px;align-items:center;padding:4px 0">
                    <input type="checkbox" name="fix_second_out"><span>Second Out</span><span class="sc subtle"></span></label>
            </fieldset>

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
            <button type="submit" id="corrSubmit" disabled>Submit Correction</button>
        </form>
    </div>
</div>

<script>
const SCHED = <?= json_encode($roster ?? [], JSON_UNESCAPED_UNICODE) ?>;
function fmt12(t){ if(!t) return ''; const [h,m]=t.split(':').map(Number);
  const ap=h<12?'am':'pm', hh=((h+11)%12)+1; return hh+':'+String(m).padStart(2,'0')+' '+ap; }
function onCorrDate(){
  const d=document.getElementById('corrDate').value;
  const s=SCHED[d];
  const set=document.getElementById('punchSet');
  const note=document.getElementById('schedNote');
  const submit=document.getElementById('corrSubmit');
  document.querySelectorAll('#punchSet input[type=checkbox]').forEach(c=>c.checked=false);

  if(!s){
    set.disabled=true; submit.disabled=true;
    note.innerHTML=d ? '⚠ No approved duty roster for this day — nothing to correct against.' : 'Select a day to see its rostered shift.';
    return;
  }
  set.disabled=false; submit.disabled=false;

  // Show/enable Second In/Out only on split-duty days.
  document.querySelectorAll('#punchSet .secOnly').forEach(r=> r.style.display = s.split ? 'flex' : 'none');

  // Per-punch: show the scheduled target time; disable a punch with no scheduled time.
  document.querySelectorAll('#punchSet .pchk').forEach(row=>{
    const f=row.dataset.f, cb=row.querySelector('input'), sc=row.querySelector('.sc');
    if(s[f]){ cb.disabled=false; sc.textContent='→ resets to '+fmt12(s[f]); }
    else    { cb.disabled=true;  cb.checked=false; sc.textContent=''; }
  });

  const parts=['first_in','first_out','second_in','second_out'].filter(f=>s[f])
    .map(f=>({first_in:'In',first_out:'Out',second_in:'2nd In',second_out:'2nd Out'}[f]+' '+fmt12(s[f])));
  note.innerHTML='Rostered'+(s.split?' (split duty)':'')+': <strong>'+parts.join(' · ')+'</strong>';
}
document.getElementById('corrForm').addEventListener('submit',function(ev){
  const any=[...document.querySelectorAll('#punchSet input[type=checkbox]')].some(c=>c.checked);
  if(!any){ ev.preventDefault(); alert('Tick at least one punch to correct.'); }
});
</script>

<div class="card">
    <h2 style="margin-top:0">Recent Requests</h2>
    <table class="tbl"><thead><tr><th>#</th><th>Requested</th><th>Day</th><th>Type</th><th>Reason</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><?= e($r['id']) ?></td>
                <td class="subtle"><?= $r['requested_at'] ? date('d M Y', strtotime($r['requested_at'])) : '' ?></td>
                <td><?= !empty($r['work_date']) ? date('d M', strtotime($r['work_date'])) : '' ?></td>
                <td><?= e($r['type_label'] ?? '') ?></td>
                <td><?= e($r['reason'] ?? '') ?></td>
                <td><span class="chip pending"><?= e($r['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="6" class="subtle center">No requests yet.</td></tr><?php endif; ?>
    </tbody></table>
</div>
<?php endif; ?>
