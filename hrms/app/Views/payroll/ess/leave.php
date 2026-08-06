<?php use App\Payroll\Repositories\LeaveRequestRepository as LR;
$attachSet = array_map('strtolower', $attachTypes);
$balanceSet = $balanceTypes;
$accept = '.' . implode(',.', array_map('strtolower', $allowedExt));
?>
<div class="page-head"><div><h1>My Leave</h1>
    <p class="subtle">Request leave, attach a supporting document, and track approval.</p></div></div>

<?php if ($balances): ?>
<div class="bal-grid">
    <?php foreach ($balances as $b): ?>
    <div class="bal">
        <span class="bal-type"><?= e($b['type']) ?></span>
        <span class="bal-avail"><?= rtrim(rtrim(number_format($b['available'], 1), '0'), '.') ?></span>
        <span class="bal-sub">days available</span>
        <span class="bal-meta"><?= (float) $b['entitlement'] ?> granted ·
            <?= (float) $b['used'] ?> used<?= $b['pending'] > 0 ? ' · ' . (float) $b['pending'] . ' pending' : '' ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2 class="panel-title">New leave request</h2>
    <form method="post" action="<?= url('me/leave/save') ?>" enctype="multipart/form-data" id="leaveForm">
        <?= csrf_field() ?>
        <div class="lf-row">
            <div class="field"><label>Type</label>
                <select name="leave_type" id="leaveType">
                    <?php foreach ($types as $t): ?>
                        <option value="<?= e($t) ?>"
                            data-attach="<?= in_array(strtolower($t), $attachSet, true) ? '1' : '0' ?>"
                            data-balance="<?= in_array($t, $balanceSet, true) ? '1' : '0' ?>"><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="field"><label>From</label><input type="date" name="from_date" id="fromDate" required></div>
            <div class="field"><label>To</label><input type="date" name="to_date" id="toDate" required></div>
            <div class="field"><label>Days</label><input type="text" id="dayCount" value="—" readonly style="width:70px;text-align:center"></div>
            <div class="field"><label>Contact while away</label><input name="contact" style="width:150px"></div>
            <div class="field grow"><label>Reason</label><input name="reason"></div>
        </div>

        <div class="attach" id="attachBlock">
            <label class="attach-label" id="attachLabel">Supporting document <span class="subtle">(optional)</span></label>
            <div class="attach-controls">
                <label class="file-btn">
                    <span>📎 Choose file</span>
                    <input type="file" name="attachment" id="attachInput" accept="<?= e($accept) ?>,image/*,application/pdf">
                </label>
                <label class="file-btn cam">
                    <span>📷 Take photo</span>
                    <input type="file" name="attachment_cam" id="camInput" accept="image/*" capture="environment">
                </label>
                <span class="file-name subtle" id="fileName">No file chosen</span>
            </div>
            <p class="attach-hint subtle">Accepted: <?= e(strtoupper(implode(', ', $allowedExt))) ?>. On a phone you can
                photograph the note; scanned images are read automatically so HR can see the text.</p>
        </div>

        <button type="submit" class="btn-primary">Submit request</button>
    </form>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Type</th><th>From</th><th>To</th><th class="num">Days</th><th>Reason</th><th>Doc</th><th>Status</th><th>Decision</th></tr></thead>
    <tbody>
    <?php foreach ($requests as $r): $st = (int) $r['StateID']; ?>
        <tr>
            <td><?= e($r['LeaveType']) ?></td>
            <td><?= date('d/m/Y', strtotime((string) $r['FromDate'])) ?></td>
            <td><?= date('d/m/Y', strtotime((string) $r['ToDate'])) ?></td>
            <td class="num"><?= (float) $r['Days'] ?></td>
            <td class="subtle"><?= e($r['Reason']) ?></td>
            <td><?php if (!empty($r['AttachmentPath'])): ?>
                <a href="<?= url('me/leave/attachment?id=' . (int) $r['RequestID']) ?>" target="_blank" rel="noopener" title="<?= e($r['AttachmentName'] ?? 'attachment') ?>">📎 view</a>
            <?php else: ?><span class="subtle">—</span><?php endif; ?></td>
            <td><span class="chip <?= $st === LR::APPROVED ? 'present' : ($st === LR::PENDING ? 'pending' : 'day_off') ?>">
                <?= e(LR::STATE_LABELS[$st] ?? $st) ?></span></td>
            <td class="subtle"><?= e($r['DecisionNote']) ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$requests): ?><tr><td colspan="8" class="center subtle">No leave requests yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>

<style>
  .bal-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px}
  .bal{border:1px solid #e4e9f1;border-radius:10px;background:#fbfcfe;padding:12px 14px;display:flex;flex-direction:column;gap:2px}
  .bal-type{font-size:12px;color:#6b7787;text-transform:uppercase;letter-spacing:.04em}
  .bal-avail{font-size:26px;font-weight:700;color:#12324f;line-height:1.1}
  .bal-sub{font-size:11px;color:#93a0b2}
  .bal-meta{font-size:11px;color:#6b7787;margin-top:4px}
  #leaveForm .lf-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
  #leaveForm .field{display:flex;flex-direction:column;gap:4px}
  #leaveForm .field.grow{flex:1;min-width:200px}
  #leaveForm .field label{font-size:12px;color:#6b7787}
  #leaveForm input,#leaveForm select{padding:7px 9px;border:1px solid #cdd6e2;border-radius:8px;font-size:14px}
  .attach{margin:14px 0;padding:12px 14px;border:1px dashed #cdd6e2;border-radius:10px;background:#fafbfd}
  .attach-label{font-weight:600;font-size:13px;color:#33414f;display:block;margin-bottom:8px}
  .attach-controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
  .file-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border:1px solid #bcd4ec;border-radius:8px;
            background:#eef4fb;color:#0f3f6b;font-size:13px;font-weight:600;cursor:pointer}
  .file-btn:hover{background:#e2eefb}
  .file-btn input{display:none}
  .file-btn.cam{display:none}                      /* camera button only where a camera is likely */
  @media(hover:none) and (pointer:coarse){ .file-btn.cam{display:inline-flex} }
  .file-name{font-size:13px}
  .attach-hint{margin:8px 0 0;font-size:12px}
  .btn-primary{margin-top:12px;padding:9px 18px;background:#137fc4;color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer}
  .btn-primary:hover{background:#0f6ba8}
</style>
<script>
(function(){
  var sel=document.getElementById('leaveType'), from=document.getElementById('fromDate'),
      to=document.getElementById('toDate'), days=document.getElementById('dayCount'),
      label=document.getElementById('attachLabel'), name=document.getElementById('fileName'),
      f1=document.getElementById('attachInput'), f2=document.getElementById('camInput');
  function calcDays(){
    if(!from.value||!to.value){days.value='—';return;}
    var a=new Date(from.value),b=new Date(to.value);
    if(b<a){days.value='!';return;}
    days.value=Math.round((b-a)/86400000)+1;
  }
  function syncType(){
    var o=sel.options[sel.selectedIndex];
    var need=o&&o.getAttribute('data-attach')==='1';
    label.innerHTML='Supporting document '+(need
      ? '<span style="color:#c0392b">(recommended for '+o.value+' leave)</span>'
      : '<span class="subtle">(optional)</span>');
  }
  // one file field wins: clear the other so only a single "attachment" is posted
  function pick(a,b){ return function(){ if(a.files.length){ b.value=''; name.textContent=a.files[0].name; } }; }
  f1&&f1.addEventListener('change',pick(f1,f2));
  f2&&f2.addEventListener('change',pick(f2,f1));
  [from,to].forEach(function(el){el&&el.addEventListener('change',calcDays);});
  sel&&sel.addEventListener('change',syncType);
  syncType();
})();
</script>
