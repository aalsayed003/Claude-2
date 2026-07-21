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

<div class="card" style="margin-top:22px">
    <div class="panel-title">Pending Approvals</div>
    <div class="kpi-wrap">
        <div>
            <div class="kpi"><span>Schedules</span><b><?= $counts['schedules'] ?></b></div>
            <div class="kpi"><span>Correction Requests</span><b><?= $counts['corrections'] ?></b></div>
            <div class="kpi"><span>Schedule Changes</span><b><?= $counts['schedule_changes'] ?></b></div>
            <div class="kpi warn"><span>Odd Punch</span><b><?= $counts['odd_punch'] ?></b></div>
            <div class="kpi"><span>Today Staff Absent</span><b><?= $counts['absent_today'] ?></b></div>
            <div class="kpi"><span>Today Staff Day Off</span><b><?= $counts['dayoff_today'] ?></b></div>
        </div>
        <div>
            <div class="kpi danger"><span>Late(s) / Early Out(s) Today</span>
                <b><?= $counts['late_today'] ?> / <?= $counts['early_today'] ?></b></div>
            <p class="subtle" style="padding:10px 12px">
                Counts reflect the selected attendance period and today's date.
                Approvals route through Dept&nbsp;Head → FA → MRD → COO/MD/CNO.
            </p>
        </div>
    </div>
</div>
