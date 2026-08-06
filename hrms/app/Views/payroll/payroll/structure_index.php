<div class="page-head">
    <div><h1>Salary Structures</h1><p class="subtle">The structure in force for the selected month. Changes are effective-dated, never overwritten.</p></div>
</div>

<div class="card">
<form method="get" action="<?= url('payroll/structures') ?>" class="inline">
    <div class="field" style="min-width:260px"><label>Search</label>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name or employee code"></div>
    <div class="field"><label>In force for</label>
        <input type="month" name="month" value="<?= e(date('Y-m', strtotime($month))) ?>"></div>
    <button type="submit">Search</button>
</form>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr>
        <th>Code</th><th>Employee</th><th>Department</th><th>Designation</th>
        <th class="num">Basic</th><th class="num">Gross</th><th>Effective from</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= e($r['emp_id']) ?></td>
            <td><?= e($r['full_name']) ?></td>
            <td class="subtle"><?= e($r['dept_name']) ?></td>
            <td class="subtle"><?= e($r['designation']) ?></td>
            <td class="num"><?= $r['basic'] !== null ? money($r['basic']) : '<span class="chip pending">none</span>' ?></td>
            <td class="num"><?= $r['gross'] !== null ? money($r['gross']) : '' ?></td>
            <td class="subtle"><?= $r['effective'] ? date('M Y', strtotime((string) $r['effective'])) : '' ?></td>
            <td><a class="btn-ghost btn-sm"
                   href="<?= url('payroll/structure?employee_id=' . (int) $r['id'] . '&month=' . date('Y-m', strtotime($month))) ?>">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="center subtle">No employees matched.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
