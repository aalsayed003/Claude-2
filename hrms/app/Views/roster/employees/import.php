<div class="page-head">
    <div><h1>Import Employees</h1><p class="subtle">Bulk-load staff from a CSV or Excel file.</p></div>
    <a class="btn btn-muted" href="<?= url('employees') ?>">← Employees</a>
</div>

<?php if (!$preview): ?>
<div class="grid" style="grid-template-columns:1.2fr 1fr">
    <div class="card">
        <h2 style="margin-top:0">Upload file</h2>
        <form method="post" action="<?= url('employees/import/preview') ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="field">
                <label>CSV or XLSX file</label>
                <input type="file" name="file" accept=".csv,.xlsx" required>
            </div>
            <button type="submit">Upload &amp; Preview</button>
            <a class="btn btn-muted" href="<?= url('employees/import/template') ?>">Download CSV template</a>
        </form>
        <p class="subtle" style="margin-top:12px">
            Nothing is saved until you review the preview and confirm.
            Existing employees (matched by <strong>Employee ID</strong>) are updated;
            new departments and sections are created automatically.
        </p>
    </div>
    <div class="card">
        <h2 style="margin-top:0">Expected columns</h2>
        <table class="tbl">
            <thead><tr><th>Column</th><th>Notes</th></tr></thead>
            <tbody>
                <tr><td><strong>emp_id</strong> *</td><td>Employee code (e.g. 01732)</td></tr>
                <tr><td><strong>pin</strong></td><td>9-digit biometric PIN. If blank, derived from emp_id</td></tr>
                <tr><td><strong>full_name</strong> *</td><td>Employee name</td></tr>
                <tr><td>department</td><td>Auto-created if new</td></tr>
                <tr><td>section</td><td>Auto-created under the department</td></tr>
                <tr><td>designation</td><td>Job title (optional)</td></tr>
                <tr><td>is_dept_head</td><td>yes / no</td></tr>
                <tr><td>active</td><td>yes / no (default yes)</td></tr>
            </tbody>
        </table>
        <p class="subtle">Header names are matched ignoring case, spaces and underscores.</p>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="inline" style="justify-content:space-between">
        <div>
            <strong><?= $preview['total'] ?></strong> row(s) read —
            <span class="chip present"><?= $preview['valid'] ?> valid</span>
            <?php if ($preview['invalid']): ?><span class="chip rejected"><?= $preview['invalid'] ?> invalid (will be skipped)</span><?php endif; ?>
        </div>
        <div class="actions">
            <?php if ($preview['valid'] > 0): ?>
            <form method="post" action="<?= url('employees/import/commit') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($preview['token']) ?>">
                <button type="submit" class="btn-ok">Confirm import of <?= $preview['valid'] ?> row(s)</button>
            </form>
            <?php endif; ?>
            <a class="btn btn-muted" href="<?= url('employees/import') ?>">Cancel</a>
        </div>
    </div>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr>
        <th>#</th><th>Status</th><th>Emp ID</th><th>PIN</th><th>Name</th>
        <th>Department</th><th>Section</th><th>Designation</th><th>Head?</th><th>Active</th>
    </tr></thead>
    <tbody>
    <?php foreach ($preview['rows'] as $i => $r): ?>
        <tr class="<?= $r['_valid']?'':'row-absent' ?>">
            <td><?= $i+1 ?></td>
            <td><?php if ($r['_valid']): ?><span class="chip present">OK</span>
                <?php else: ?><span class="chip rejected" title="<?= e($r['_errors']) ?>">skip</span><?php endif; ?></td>
            <td><?= e($r['emp_id']) ?></td>
            <td><?= e($r['pin']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <td><?= e($r['department']) ?></td>
            <td><?= e($r['section']) ?></td>
            <td><?= e($r['designation']) ?></td>
            <td class="center"><?= $r['is_dept_head']?'✔':'' ?></td>
            <td class="center"><?= $r['active']?'✔':'—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php if ($preview['invalid']): ?>
<p class="subtle">Invalid rows are highlighted; hover the <em>skip</em> tag to see why. Fix them in your file and re-upload, or import the valid rows now.</p>
<?php endif; ?>
<?php endif; ?>
