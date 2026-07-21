<div class="page-head"><div><h1>Submit Duty Roster</h1>
    <p class="subtle">Send a department/section roster into the approval chain.</p></div>
    <a class="btn btn-muted" href="<?= url('roster') ?>">← Roster</a></div>
<div class="card" style="max-width:560px">
<form method="post" action="<?= url('roster/submit') ?>">
    <?= csrf_field() ?>
    <div class="field"><label>Month</label><input type="month" name="period" value="<?= e($period) ?>" required></div>
    <div class="field"><label>Department *</label>
        <select name="department_id" required>
            <option value="">Select…</option>
            <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="field"><label>Section</label>
        <select name="section_id">
            <option value="">All / not applicable</option>
            <?php foreach ($sections as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select></div>
    <button type="submit">Submit for Approval</button>
</form>
</div>
