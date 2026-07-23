<div class="page-head">
    <div><h1>Duty Roster</h1><p class="subtle">Assigned schedule coverage for <strong><?= e(period_label($period)) ?></strong>.</p></div>
    <form method="get" action="<?= url('roster') ?>" class="inline">
        <div class="field"><input type="month" name="period" value="<?= e($period) ?>"></div>
        <button type="submit">Go</button>
    </form>
</div>
<div class="tbl-wrap">
<table class="tbl">
    <thead><tr><th>Emp ID</th><th>Name</th><th class="num">Days Assigned</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= e($r['emp_id']) ?></strong></td>
            <td><?= e($r['full_name']) ?></td>
            <td class="num"><?= (int)$r['assigned_days'] ?></td>
            <td><a class="btn btn-sm" href="<?= url('roster/allot?employee_id='.$r['id'].'&period='.$period) ?>">Allot Shift</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
