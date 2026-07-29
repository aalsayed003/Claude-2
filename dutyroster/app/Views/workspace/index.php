<?php
function wtm($v){ return $v ? date('h:i a', strtotime($v)) : ''; }
?>
<div class="page-head">
    <div><h1>Attendance</h1>
        <p class="subtle">View attendance, or use Correct / Schedule on any day to request a change.</p></div>
    <?php if (\App\Core\Auth::atLeast('dept_head')): ?>
    <form method="post" action="<?= url('attendance/rebuild') ?>" onsubmit="return confirm('Recompute attendance for the current period?')">
        <?= csrf_field() ?><button class="btn btn-muted btn-sm">Recompute period</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
<form method="get" action="<?= url('attendance') ?>" class="inline" id="wsFilter">
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
    <div class="field"><label>Cutoff Period</label><input type="month" name="period" value="<?= e($period) ?>"></div>
    <button type="submit">Show</button>
</form>
<p class="subtle" style="margin:6px 0 0">Cutoff window: <strong><?= date('d M Y', strtotime($cutFrom)) ?> → <?= date('d M Y', strtotime($cutTo)) ?></strong></p>
</div>

<?php if ($emp): ?>

<div class="card compact-head">
    <strong><?= e($emp['emp_id'].' · '.$emp['full_name']) ?></strong>
    <div class="legend">
        <span class="leg"><span class="sw no_punch"></span> No Punching</span>
        <span class="leg"><span class="sw holiday"></span> Holiday</span>
        <span class="leg"><span class="sw day_off"></span> Day Off</span>
        <span class="leg"><span class="sw leave"></span> Leave</span>
    </div>
</div>
<div class="tbl-wrap compact-tbl">
<table class="tbl">
    <thead>
        <tr>
            <th rowspan="2">Date</th>
            <th colspan="4" class="center">Actual Timings</th>
            <th colspan="4" class="center">Scheduled Timings</th>
            <th rowspan="2" class="num">Late In</th><th rowspan="2" class="num">Early Out</th><th rowspan="2">Status</th>
            <th rowspan="2">Actions</th>
        </tr>
        <tr>
            <th>First In</th><th>First Out</th><th>Second In</th><th>Second Out</th>
            <th>First In</th><th>First Out</th><th>Second In</th><th>Second Out</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($attendanceRows as $r): $cls='row-'.$r['status']; ?>
        <tr class="<?= in_array($r['status'],['day_off','holiday','leave','absent','no_punch'])?$cls:'' ?>">
            <td><strong><?= date('d/m/Y', strtotime($r['work_date'])) ?></strong> <span class="subtle"><?= date('D', strtotime($r['work_date'])) ?></span></td>
            <?php if (in_array($r['status'],['day_off','holiday','leave'])): ?>
                <td colspan="4" class="center"><span class="chip <?= $r['status'] ?>"><?= strtoupper(str_replace('_',' ',$r['status'])) ?></span></td>
            <?php else: ?>
                <td><?= wtm($r['act_first_in']) ?></td>
                <td><?= wtm($r['act_first_out']) ?></td>
                <td><?= wtm($r['act_second_in']) ?></td>
                <td><?= wtm($r['act_second_out']) ?></td>
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
            <td class="rowActions" style="display:flex;flex-wrap:nowrap;gap:4px;white-space:nowrap">
                <button type="button" class="btn btn-sm btn-muted" onclick="openCorrectionModal('<?= e($r['work_date']) ?>')">Correct</button>
                <button type="button" class="btn btn-sm btn-muted" onclick="openScheduleModal('<?= e($r['work_date']) ?>')">Schedule</button>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$attendanceRows): ?><tr><td colspan="13" class="center subtle">No attendance rows for this period.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>

<div class="card compact-head" style="margin-top:12px">
    <h3 style="margin:0 0 2px">Requests — <?= e(period_label($period)) ?></h3>
    <p class="subtle" style="margin:0 0 8px">Every correction and schedule-change request raised for this period, newest first.</p>
    <div class="tbl-wrap compact-tbl" style="max-height:320px">
    <table class="tbl"><thead><tr><th>Day</th><th>Type</th><th>Details</th><th>Reason</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($allRequests as $r): ?>
            <tr>
                <td><?= !empty($r['work_date']) ? date('d M', strtotime($r['work_date'])) : '' ?></td>
                <td><?= e($r['type']) ?></td>
                <td><?= e($r['detail']) ?></td>
                <td><?= e($r['reason']) ?></td>
                <td><span class="chip pending"><?= e($r['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$allRequests): ?><tr><td colspan="5" class="subtle center">No requests for this period yet.</td></tr><?php endif; ?>
    </tbody></table>
    </div>
</div>

<!-- ===================== MODALS: Correct Attendance / Change Schedule ===================== -->
<div id="wsModalOverlay" class="ws-modal-overlay" style="display:none">
  <div class="ws-modal-box">
    <div id="wsModalCorrection" style="display:none">
        <div class="ws-modal-head">
            <h3 style="margin:0">Correct Attendance — <span id="mCorrDateLabel"></span></h3>
            <button type="button" class="ws-modal-x" onclick="closeWsModal()">&times;</button>
        </div>
        <p class="subtle" style="margin-top:-4px">Tick which punch is wrong. On approval it resets to the <strong>scheduled roster time</strong> — you don't enter a time.</p>
        <form method="post" action="<?= url('correction/save') ?>" id="mCorrForm">
            <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <input type="hidden" name="work_date" id="mCorrDate">
            <div id="mSchedNote" class="subtle" style="margin:0 0 10px">Select a day to see its rostered shift.</div>
            <fieldset id="mPunchSet" style="border:1px solid #e2e6ee;border-radius:8px;padding:6px 12px;margin:0 0 12px" disabled>
                <legend class="subtle" style="padding:0 6px">Which punch needs correcting?</legend>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 14px">
                    <label class="pchk punchTile" data-f="first_in">
                        <input type="checkbox" name="fix_first_in"><span class="pt-label">First In</span><span class="sc subtle"></span></label>
                    <label class="pchk punchTile" data-f="first_out">
                        <input type="checkbox" name="fix_first_out"><span class="pt-label">First Out</span><span class="sc subtle"></span></label>
                    <label class="pchk punchTile secOnly" data-f="second_in">
                        <input type="checkbox" name="fix_second_in"><span class="pt-label">Second In</span><span class="sc subtle"></span></label>
                    <label class="pchk punchTile secOnly" data-f="second_out">
                        <input type="checkbox" name="fix_second_out"><span class="pt-label">Second Out</span><span class="sc subtle"></span></label>
                </div>
            </fieldset>
            <div class="field"><label>Reason</label>
                <select name="reason">
                    <?php if (!empty($reasons)): ?>
                        <?php foreach ($reasons as $rn): ?><option value="<?= e($rn['id']) ?>"><?= e($rn['name']) ?></option><?php endforeach; ?>
                    <?php else: ?>
                        <option>Forgot to punch</option><option>Official duty</option>
                        <option>Appointment</option><option>Device error</option><option>Others</option>
                    <?php endif; ?>
                </select></div>
            <div class="field"><label>Remarks</label><textarea name="remarks" rows="2"></textarea></div>
            <div class="actions">
                <button type="submit" id="mCorrSubmit" disabled>Submit Correction</button>
                <button type="button" class="btn btn-muted" onclick="closeWsModal()">Cancel</button>
            </div>
        </form>
    </div>

    <div id="wsModalSchedule" style="display:none">
        <div class="ws-modal-head">
            <h3 style="margin:0">Change Schedule — <span id="mScDateLabel"></span></h3>
            <button type="button" class="ws-modal-x" onclick="closeWsModal()">&times;</button>
        </div>
        <p class="subtle" style="margin-top:-4px">The current shift fills in automatically. Choose the new shift you want instead.</p>
        <form method="post" action="<?= url('schedule-change/save') ?>" id="mScForm">
            <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>">
            <input type="hidden" name="period" value="<?= e($period) ?>">
            <input type="hidden" name="work_date" id="mScDate">
            <input type="hidden" name="old_shift_id" id="mScOldShiftId">
            <div class="field"><label>Current (Old) Shift</label>
                <input type="text" id="mScOldShiftDisplay" readonly style="background:#f3f5f9;color:#555"></div>
            <div class="field"><label>New Shift *</label>
                <select name="new_shift_id" required>
                    <option value="">Select…</option>
                    <?php foreach ($shifts as $s): ?><option value="<?= $s['id'] ?>"><?= e(shift_label($s)) ?></option><?php endforeach; ?>
                </select></div>
            <div class="field"><label>Claim Time</label><input name="claim_time" placeholder="e.g. 8h"></div>
            <div class="actions">
                <button type="submit">Submit Request</button>
                <button type="button" class="btn btn-muted" onclick="closeWsModal()">Cancel</button>
            </div>
        </form>
    </div>
  </div>
</div>

<script>
const WS_ROSTER = <?= json_encode($roster ?? [], JSON_UNESCAPED_UNICODE) ?>;
function fmt12(t){ if(!t) return ''; const [h,m]=t.split(':').map(Number);
  const ap=h<12?'am':'pm', hh=((h+11)%12)+1; return hh+':'+String(m).padStart(2,'0')+' '+ap; }

function fillPunchUI(date, ids){
  const s=WS_ROSTER[date];
  const set=document.getElementById(ids.set), note=document.getElementById(ids.note), submit=document.getElementById(ids.submit);
  set.querySelectorAll('input[type=checkbox]').forEach(c=>c.checked=false);
  if(!s){
    set.disabled=true; if(submit) submit.disabled=true;
    note.innerHTML = date ? '⚠ No approved duty roster for this day — nothing to correct against.' : 'Select a day to see its rostered shift.';
    return;
  }
  set.disabled=false; if(submit) submit.disabled=false;
  set.querySelectorAll('.secOnly').forEach(row=> row.style.display = s.split ? '' : 'none');
  set.querySelectorAll('.pchk').forEach(row=>{
    const f=row.dataset.f, cb=row.querySelector('input'), sc=row.querySelector('.sc');
    if(s[f]){ cb.disabled=false; sc.textContent='→ resets to '+fmt12(s[f]); row.classList.remove('disabledPunch'); }
    else    { cb.disabled=true;  cb.checked=false; sc.textContent=''; row.classList.add('disabledPunch'); }
  });
  const parts=['first_in','first_out','second_in','second_out'].filter(f=>s[f])
    .map(f=>({first_in:'In',first_out:'Out',second_in:'2nd In',second_out:'2nd Out'}[f]+' '+fmt12(s[f])));
  note.innerHTML='Rostered ('+(s.code||'shift')+(s.split?', split duty':'')+'): <strong>'+parts.join(' · ')+'</strong>';
}
function fillOldShift(date, dispId, hidId){
  const s=WS_ROSTER[date];
  const disp=document.getElementById(dispId), hid=document.getElementById(hidId);
  if(!s){ disp.value = date ? 'No roster found for this day' : ''; hid.value=''; return; }
  const parts=['first_in','first_out','second_in','second_out'].filter(f=>s[f]).map(f=>fmt12(s[f]));
  disp.value = s.code + (parts.length ? ' (' + parts.join('–') + ')' : '');
  hid.value = s.shift_id || '';
}

function closeWsModal(){ document.getElementById('wsModalOverlay').style.display='none'; }
document.getElementById('wsModalOverlay')?.addEventListener('click', function(ev){ if(ev.target===this) closeWsModal(); });
function openCorrectionModal(date){
  document.getElementById('mCorrDate').value = date;
  document.getElementById('mCorrDateLabel').textContent = date;
  fillPunchUI(date, {set:'mPunchSet', note:'mSchedNote', submit:'mCorrSubmit'});
  document.getElementById('wsModalCorrection').style.display='block';
  document.getElementById('wsModalSchedule').style.display='none';
  document.getElementById('wsModalOverlay').style.display='flex';
}
function openScheduleModal(date){
  document.getElementById('mScDate').value = date;
  document.getElementById('mScDateLabel').textContent = date;
  fillOldShift(date, 'mScOldShiftDisplay', 'mScOldShiftId');
  document.getElementById('wsModalSchedule').style.display='block';
  document.getElementById('wsModalCorrection').style.display='none';
  document.getElementById('wsModalOverlay').style.display='flex';
}
document.getElementById('mCorrForm')?.addEventListener('submit', function(ev){
  const any=[...document.querySelectorAll('#mPunchSet input[type=checkbox]')].some(c=>c.checked);
  if(!any){ ev.preventDefault(); alert('Tick at least one punch to correct.'); }
});
document.getElementById('mScForm')?.addEventListener('submit', function(ev){
  if(!document.getElementById('mScOldShiftId').value){
    if(!confirm('No current roster was found for this day, so there is no "old shift" on record. Submit anyway?')) ev.preventDefault();
  }
});
</script>

<style>
.punchTile{display:flex;gap:8px;align-items:center;padding:4px 8px;border-radius:6px}
.punchTile.disabledPunch{opacity:.45}
.punchTile input[type=checkbox]{width:16px;height:16px}
.pt-label{font-weight:600}
.ws-modal-overlay{position:fixed;inset:0;background:rgba(20,25,40,.45);z-index:1000;align-items:center;justify-content:center;padding:20px}
.ws-modal-box{background:#fff;border-radius:10px;padding:18px 20px;max-width:480px;width:100%;max-height:90vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.ws-modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.ws-modal-x{background:none;border:none;font-size:22px;line-height:1;cursor:pointer;color:#7a8296;padding:0 4px}

/* Denser layout for this page specifically */
.compact-head{padding:8px 14px;margin-bottom:10px}
.compact-tbl .tbl th,.compact-tbl .tbl td{padding:4px 8px;font-size:12.5px;line-height:1.3}
.compact-tbl .legend{margin:4px 0}
.legend{font-size:11px}
h1{font-size:19px}
.page-head{margin-bottom:10px}
</style>

<?php elseif (!$canPickAnyone): ?>
    <div class="card subtle">Your account is not linked to an employee record yet. Ask an administrator to link it.</div>
<?php endif; ?>
