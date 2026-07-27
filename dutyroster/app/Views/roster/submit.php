<?php $import = $import ?? null; ?>
<div class="page-head"><div><h1>Submit Duty Roster</h1>
    <p class="subtle">Prepare a department roster in Excel, upload it, and send it into the approval chain.</p></div>
    <a class="btn btn-muted" href="<?= url('roster') ?>">← Roster</a></div>

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
        <h3 style="margin-top:0">1 · Download the Excel template</h3>
        <p class="subtle">Pick a department and month. The file comes pre-filled with that team's employees and a
            shift dropdown on every day — team leaders just choose shifts and save.</p>
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
            <button type="submit">⬇ Download template (.xlsx)</button>
        </form>
    </div>

    <div class="card">
        <h3 style="margin-top:0">2 · Upload the filled roster</h3>
        <p class="subtle">Upload the completed template. Every Employee ID, date and shift is checked first; if anything
            is wrong the upload is rejected with the exact cells to fix, and nothing is saved.</p>
        <form method="post" action="<?= url('roster/import') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field"><label>Roster file (.xlsx)</label>
                <input type="file" name="file" accept=".xlsx,.csv" required></div>
            <div class="field"><label>Department</label>
                <select name="department_id">
                    <option value="">Auto-detect from the file</option>
                    <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="subtle">Leave on auto-detect for a downloaded template; pick one only for a plain CSV.</span></div>
            <div class="field"><label>Month</label>
                <input type="month" name="period" value="<?= e($period) ?>"></div>
            <button type="submit">⬆ Upload &amp; submit for approval</button>
        </form>
    </div>

</div>

<div class="card" style="max-width:560px">
    <h3 style="margin-top:0">Or submit a whole department manually</h3>
    <p class="subtle">Use this to push an already-prepared roster into the approval chain, or to re-submit after a rejection.</p>
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
