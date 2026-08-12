<div class="page-head"><div><h1>Leave Requests</h1><p class="subtle">Pending leave submitted by staff.</p></div></div>
<div class="card" style="padding:10px 14px"><div class="actions">
    <a class="btn-ghost btn-sm" href="<?= url('hr/leave') ?>">Leave requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/requests') ?>">HR requests</a>
    <a class="btn-ghost btn-sm" href="<?= url('hr/cme') ?>">CME compliance</a>
</div></div>

<div class="tbl-wrap">
<table class="tbl tbl--cards">
    <thead><tr><th>Submitted</th><th>Employee</th><th>Department</th><th>Type</th><th>From</th><th>To</th>
        <th class="num">Days</th><th>Reason</th><th>Document</th><th style="min-width:260px">Decision</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $r): ?>
        <tr>
            <td data-label="Submitted" class="subtle"><?= date('d/m/Y', strtotime((string) $r['CreatedAt'])) ?></td>
            <td data-label="Employee"><?= e($r['emp_code'] . ' · ' . $r['emp_name']) ?></td>
            <td data-label="Department" class="subtle"><?= e($r['dept_name']) ?></td>
            <td data-label="Type"><?= e($r['LeaveType']) ?></td>
            <td data-label="From"><?= date('d/m/Y', strtotime((string) $r['FromDate'])) ?></td>
            <td data-label="To"><?= date('d/m/Y', strtotime((string) $r['ToDate'])) ?></td>
            <td data-label="Days" class="num"><?= (float) $r['Days'] ?></td>
            <td data-label="Reason" class="subtle"><?= e($r['Reason']) ?></td>
            <td data-label="Document">
                <?php if (!empty($r['AttachmentPath'])): ?>
                    <a class="doc-link" href="<?= url('me/leave/attachment?id=' . (int) $r['RequestID']) ?>"
                       target="_blank" rel="noopener">📎 <?= e($r['AttachmentName'] ?: 'view') ?></a>
                    <?php if (!empty($r['AttachmentOcr'])): ?>
                        <details class="ocr"><summary>scanned text</summary>
                            <pre><?= e($r['AttachmentOcr']) ?></pre></details>
                    <?php endif; ?>
                <?php else: ?><span class="subtle">—</span><?php endif; ?>
            </td>
            <td class="cell-actions">
                <form method="post" action="<?= url('hr/leave/decide') ?>" class="inline" style="gap:6px">
                    <?= csrf_field() ?>
                    <input type="hidden" name="request_id" value="<?= (int) $r['RequestID'] ?>">
                    <input name="note" placeholder="note" style="width:110px">
                    <button class="btn-ok btn-sm" name="decision" value="approve">Approve</button>
                    <button class="btn-muted btn-sm" name="decision" value="reject">Reject</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$pending): ?><tr><td colspan="10" class="center subtle">No pending leave requests.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
<style>
  .doc-link{font-size:13px;color:#137fc4;text-decoration:none;font-weight:600;white-space:nowrap}
  .ocr{margin-top:4px}
  .ocr summary{font-size:12px;color:#6b7787;cursor:pointer}
  .ocr pre{max-width:320px;max-height:140px;overflow:auto;white-space:pre-wrap;background:#f6f8fb;
           border:1px solid #e4e9f1;border-radius:6px;padding:6px 8px;font-size:12px;margin:6px 0 0}
</style>
