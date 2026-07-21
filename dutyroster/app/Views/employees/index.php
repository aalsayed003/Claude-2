<div class="page-head">
    <div><h1>Employees</h1><p class="subtle">Master list, mapped to biometric PINs.</p></div>
    <?php if (\App\Core\Auth::isAdmin()): ?><a class="btn" href="<?= url('employees/new') ?>">+ New Employee</a><?php endif; ?>
</div>
<form method="get" action="<?= url('employees') ?>" class="inline" style="margin-bottom:14px">
    <div class="field" style="flex:1"><input name="q" value="<?= e($q) ?>" placeholder="Search name, ID or PIN…"></div>
    <button type="submit">Search</button>
</form>
<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Emp ID</th><th>PIN</th><th>Name</th><th>Department</th><th>Section</th><th>Head?</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($emps as $e): ?>
        <tr>
            <td><strong><?= e($e['emp_id']) ?></strong></td>
            <td><?= e($e['pin']) ?></td>
            <td><?= e($e['full_name']) ?></td>
            <td><?= e($e['dept_name']) ?></td>
            <td><?= e($e['section_name']) ?></td>
            <td class="center"><?= $e['is_dept_head'] ? '✔' : '' ?></td>
            <td><?php if (\App\Core\Auth::isAdmin()): ?><a class="btn btn-sm btn-muted" href="<?= url('employees/edit?id='.$e['id']) ?>">Edit</a><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$emps): ?><tr><td colspan="7" class="center subtle">No employees found.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
