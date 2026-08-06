<div class="page-head"><div><h1><?= e($title) ?></h1></div>
    <a class="btn btn-muted" href="<?= url('employees') ?>">← Back</a></div>
<div class="card" style="max-width:680px">
<form method="post" action="<?= url('employees/save') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= e($emp['id'] ?? '') ?>">
    <div class="inline">
        <div class="field" style="flex:1"><label>Employee ID *</label>
            <input name="emp_id" value="<?= e($emp['emp_id'] ?? '') ?>" required placeholder="01732"></div>
        <div class="field" style="flex:1"><label>Biometric PIN *</label>
            <input name="pin" value="<?= e($emp['pin'] ?? '') ?>" required placeholder="000001732"></div>
    </div>
    <div class="field"><label>Full Name *</label>
        <input name="full_name" value="<?= e($emp['full_name'] ?? '') ?>" required></div>
    <div class="inline">
        <div class="field" style="flex:1"><label>Department</label>
            <select name="department_id">
                <option value="">—</option>
                <?php foreach ($depts as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= (($emp['department_id']??'')==$d['id'])?'selected':'' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field" style="flex:1"><label>Section</label>
            <select name="section_id">
                <option value="">—</option>
                <?php foreach ($sections as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= (($emp['section_id']??'')==$s['id'])?'selected':'' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
    </div>
    <div class="field"><label>Designation</label>
        <input name="designation" value="<?= e($emp['designation'] ?? '') ?>"></div>
    <label class="checkbox"><input type="checkbox" name="is_dept_head" value="1" <?= !empty($emp['is_dept_head'])?'checked':'' ?>> Department head</label>
    <div style="margin-top:16px"><button type="submit">Save Employee</button></div>
</form>
</div>
