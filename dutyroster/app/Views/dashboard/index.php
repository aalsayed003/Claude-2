<?php require dirname(__DIR__) . '/partials/icons.php'; ?>
<div class="page-head">
    <div>
        <h1>Main Dashboard</h1>
        <p class="subtle">Attendance period <strong><?= e(period_label($period)) ?></strong></p>
    </div>
    <form method="get" action="<?= url('dashboard') ?>" class="inline">
        <div class="field">
            <label>Month for</label>
            <input type="month" name="period" value="<?= e($period) ?>">
        </div>
        <button type="submit">Refresh</button>
    </form>
</div>

<div class="grid tiles">
    <?php foreach ($tiles as $t): ?>
        <?php if (!can($t['min'])) continue; ?>
        <a class="tile" href="<?= url($t['key']) ?>">
            <span class="ic"><?= icon($t['icon']) ?></span>
            <span><?= e($t['label']) ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php
    // A "today" attendance count links to the list of people behind it; the
    // approval counts link to the Approve Request screen. dept_head+ only.
    $drill = fn($metric) => can('dept_head')
        ? url('dashboard/list?metric=' . $metric . '&date=' . $today . '&period=' . $period)
        : null;
    $kpi = function (string $label, $value, ?string $href, string $cls = '') {
        $inner = '<span>' . e($label) . '</span><b>' . $value . '</b>';
        echo $href
            ? '<a class="kpi kpi-link ' . $cls . '" href="' . $href . '">' . $inner . '</a>'
            : '<div class="kpi ' . $cls . '">' . $inner . '</div>';
    };
?>
<div class="card" style="margin-top:22px">
    <div class="panel-title">Pending Approvals &amp; Today</div>
    <div class="kpi-wrap">
        <div>
            <?php $kpi('Schedules', $counts['schedules'], can('dept_head') ? url('approvals') : null); ?>
            <?php $kpi('Correction Requests', $counts['corrections'], can('dept_head') ? url('approvals') : null); ?>
            <?php $kpi('Schedule Changes', $counts['schedule_changes'], can('dept_head') ? url('approvals') : null); ?>
            <?php $kpi('Odd Punch', $counts['odd_punch'], $drill('odd'), 'warn'); ?>
            <?php $kpi('Today Staff Absent', $counts['absent_today'], $drill('absent')); ?>
            <?php $kpi('Today Staff Day Off', $counts['dayoff_today'], $drill('day_off')); ?>
        </div>
        <div>
            <?php $kpi('Late In(s) Today', $counts['late_today'], $drill('late'), 'danger'); ?>
            <?php $kpi('Early Out(s) Today', $counts['early_today'], $drill('early'), 'danger'); ?>
            <p class="subtle" style="padding:10px 12px">
                <?php if (can('dept_head')): ?>Click a "today" figure to see exactly who they are.<br><?php endif; ?>
                Counts reflect the selected attendance period and today's date.
            </p>
        </div>
    </div>
</div>
