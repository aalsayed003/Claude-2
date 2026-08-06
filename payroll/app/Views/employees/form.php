<?php
$id = $emp['ID'] ?? 0;
$val = fn($k, $d = '') => e($emp[$k] ?? $d);
?>
<div class="page-head">
    <div><h1><?= $emp ? 'Edit employee' : 'New employee' ?></h1>
        <p class="subtle">Master record in the shared HR system. Salary and bank details are set on the structure screen.</p></div>
    <a class="btn-ghost btn-sm" href="<?= url('payroll/employees') ?>">Back</a>
</div>

<div class="card">
<form method="post" action="<?= url('payroll/employees/save') ?>">
    <?= csrf_field() ?>
    <?php if ($id): ?><input type="hidden" name="id" value="<?= (int) $id ?>"><?php endif; ?>

    <div class="grid2">
        <div class="field"><label>Employee code *</label>
            <input name="emp_code" value="<?= $val('EmpCode') ?>" required></div>
        <div class="field"><label>Joining date</label>
            <input type="date" name="joined_at"
                   value="<?= e($emp && $emp['StartDateTime'] ? date('Y-m-d', strtotime((string) $emp['StartDateTime'])) : '') ?>"></div>
    </div>

    <div class="grid2">
        <div class="field"><label>First name *</label><input name="first_name" value="<?= $val('FirstName') ?>" required></div>
        <div class="field"><label>Middle name</label><input name="middle_name" value="<?= $val('Middlename') ?>"></div>
    </div>
    <div class="grid2">
        <div class="field"><label>Last name</label><input name="last_name" value="<?= $val('Lastname') ?>"></div>
        <div class="field"><label>Sex</label>
            <select name="sex">
                <option value="">—</option>
                <option value="1" <?= (int) ($emp['Sex'] ?? 0) === 1 ? 'selected' : '' ?>>Male</option>
                <option value="2" <?= (int) ($emp['Sex'] ?? 0) === 2 ? 'selected' : '' ?>>Female</option>
            </select></div>
    </div>

    <div class="grid2">
        <div class="field"><label>Department *</label>
            <select name="department_id" required>
                <option value="">Select…</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= (int) ($emp['DepartmentId'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="field"><label>Designation</label>
            <select name="designation_id">
                <option value="">Select…</option>
                <?php foreach ($designations as $d): ?>
                    <option value="<?= (int) $d['id'] ?>" <?= (int) ($emp['DesignationId'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
    </div>

    <div class="grid2">
        <div class="field"><label>Email</label><input type="email" name="email" value="<?= $val('EMail') ?>"></div>
        <div class="field"><label>Mobile</label><input name="cell_no" value="<?= $val('CellNo') ?>"></div>
    </div>

    <div class="actions"><button type="submit"><?= $emp ? 'Save changes' : 'Add employee' ?></button></div>
    <?php if (!$emp): ?>
        <p class="muted-note">After adding, you'll go straight to the salary structure to set pay and bank details.</p>
    <?php endif; ?>
</form>
</div>
