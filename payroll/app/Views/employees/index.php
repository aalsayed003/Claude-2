<div class="page-head">
    <div><h1>Employees</h1><p class="subtle">The shared HR master. Adding here creates the record the Duty Roster also uses.</p></div>
    <a class="btn" href="<?= url('payroll/employees/new') ?>">+ New employee</a>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/employees') ?>" class="inline">
    <div class="field" style="min-width:280px"><label>Search</label>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name or employee code"></div>
    <button type="submit">Search</button>
</form>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Designation</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($employees as $e1): ?>
        <tr>
            <td><?= e($e1['emp_id']) ?></td>
            <td><?= e($e1['full_name']) ?></td>
            <td class="subtle"><?= e($e1['dept_name']) ?></td>
            <td class="subtle"><?= e($e1['designation']) ?></td>
            <td>
                <a class="btn-ghost btn-sm" href="<?= url('payroll/employees/edit?id=' . (int) $e1['id']) ?>">Edit</a>
                <a class="btn-ghost btn-sm" href="<?= url('payroll/structure?employee_id=' . (int) $e1['id']) ?>">Structure</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$employees): ?><tr><td colspan="5" class="center subtle">No employees matched.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
