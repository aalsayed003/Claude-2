<div class="page-head">
    <div><h1><?= e($label) ?> — <?= e(date('d M Y', strtotime($date))) ?></h1>
        <p class="subtle"><?= count($rows) ?> <?= count($rows) === 1 ? 'person' : 'people' ?></p></div>
    <a class="btn btn-muted" href="<?= url('dashboard?period=' . urlencode($period)) ?>">← Dashboard</a>
</div>

<div class="tbl-wrap">
<table class="tbl">
    <thead><tr>
        <th>#</th><th>Emp ID</th><th>Name</th><th>Shift</th>
        <th>Scheduled In</th><th>Scheduled Out</th><th>Actual In</th><th>Actual Out</th>
        <?php if ($metric === 'late'): ?><th class="num">Late (min)</th>
        <?php elseif ($metric === 'early'): ?><th class="num">Early (min)</th><?php endif; ?>
        <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><strong><?= e($r['emp_id']) ?></strong></td>
            <td><?= e($r['name']) ?></td>
            <td><?= e($r['shift_code']) ?></td>
            <td><?= e($r['sched_in'] ?? '—') ?></td>
            <td><?= e($r['sched_out'] ?? '—') ?></td>
            <td><?= e($r['act_in'] ?? '—') ?></td>
            <td><?= e($r['act_out'] ?? '—') ?></td>
            <?php if ($metric === 'late'): ?><td class="num"><?= $r['late_in_min'] ?: '' ?></td>
            <?php elseif ($metric === 'early'): ?><td class="num"><?= $r['early_out_min'] ?: '' ?></td><?php endif; ?>
            <td class="actions">
                <a class="btn btn-sm btn-muted"
                   href="<?= url('attendance?employee_id=' . $r['id'] . '&period=' . urlencode($period)) ?>">View</a>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
        <tr><td colspan="10" class="center subtle">No one in this category for <?= e(date('d M Y', strtotime($date))) ?>.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
