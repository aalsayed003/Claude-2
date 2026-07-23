<div class="page-head">
    <div><h1>Duty Roster Master</h1><p class="subtle">Shift definitions used across the roster.</p></div>
    <a class="btn" href="<?= url('shifts/new') ?>">+ New Shift</a>
</div>
<div class="tbl-wrap">
<table class="tbl">
    <thead><tr>
        <th>#</th><th>Code</th><th>Name</th><th>First In</th><th>First Out</th>
        <th>Second In</th><th>Second Out</th><th class="num">Total Hrs</th><th>Type</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($shifts as $i => $s): ?>
        <tr<?= $s['active'] ? '' : ' style="opacity:.5"' ?>>
            <td><?= $i+1 ?></td>
            <td><strong><?= e($s['code']) ?></strong></td>
            <td><?= e($s['name']) ?></td>
            <td><?= e(substr((string)$s['first_in'],0,5)) ?></td>
            <td><?= e(substr((string)$s['first_out'],0,5)) ?></td>
            <td><?= e(substr((string)$s['second_in'],0,5)) ?></td>
            <td><?= e(substr((string)$s['second_out'],0,5)) ?></td>
            <td class="num"><?= number_format((float)$s['total_hours'],2) ?></td>
            <td>
                <?php if ($s['is_day_off']): ?><span class="chip day_off">Day Off</span>
                <?php elseif ($s['is_holiday']): ?><span class="chip holiday">Holiday</span>
                <?php elseif ($s['crosses_midnight']): ?><span class="chip">Night</span>
                <?php else: ?><span class="chip present">Work</span><?php endif; ?>
            </td>
            <td class="actions">
                <a class="btn btn-sm btn-muted" href="<?= url('shifts/edit?id='.$s['id']) ?>">Edit</a>
                <form method="post" action="<?= url('shifts/delete') ?>" onsubmit="return confirm('Delete this shift?')">
                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm btn-danger">Del</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$shifts): ?><tr><td colspan="10" class="center subtle">No shifts yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
