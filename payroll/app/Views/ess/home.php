<?php $pct = $cmeRequired > 0 ? min(100, round($cmeDone / $cmeRequired * 100)) : 0; ?>
<div class="page-head"><div><h1>My Self-Service</h1>
    <p class="subtle">Your payslips, leave, requests to HR and training hours.</p></div></div>

<div class="grid2">
    <div class="card">
        <h2 class="panel-title">Quick actions</h2>
        <div class="actions">
            <a class="btn" href="<?= url('me/payslips') ?>">My payslips</a>
            <a class="btn-ghost" href="<?= url('me/leave') ?>">Submit leave</a>
            <a class="btn-ghost" href="<?= url('me/hr') ?>">Request to HR</a>
            <a class="btn-ghost" href="<?= url('me/cme') ?>">Log training</a>
        </div>
        <h2 class="panel-title" style="margin-top:18px">Training (CME) — <?= (int) $year ?></h2>
        <div style="background:#eef2f7;border-radius:8px;height:16px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 100 ? 'var(--ok)' : 'var(--accent)' ?>"></div>
        </div>
        <p class="subtle" style="margin-top:6px"><?= number_format($cmeDone, 1) ?> of <?= number_format($cmeRequired, 1) ?> hours (<?= $pct ?>%)</p>
    </div>

    <div class="card">
        <h2 class="panel-title">Recent payslips</h2>
        <table class="tbl"><tbody>
        <?php foreach ($payslips as $p): ?>
            <tr><td><?= date('F Y', strtotime((string) $p['month'])) ?></td>
                <td class="num"><?= money($p['NetPayment']) ?></td>
                <td><a class="btn-ghost btn-sm" href="<?= url('payroll/payslip?month=' . date('Y-m', strtotime((string) $p['month']))) ?>">View</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$payslips): ?><tr><td class="subtle center">No payslips yet.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>

<div class="grid2">
    <div class="card">
        <h2 class="panel-title">My recent leave</h2>
        <table class="tbl"><tbody>
        <?php foreach ($leave as $l): ?>
            <tr><td><?= e($l['LeaveType']) ?> · <?= date('d/m', strtotime((string) $l['FromDate'])) ?>–<?= date('d/m', strtotime((string) $l['ToDate'])) ?></td>
                <td><span class="chip <?= (int) $l['StateID'] === 2 ? 'present' : ((int) $l['StateID'] === 1 ? 'pending' : 'day_off') ?>">
                    <?= e(\App\Repositories\LeaveRequestRepository::STATE_LABELS[(int) $l['StateID']] ?? '') ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$leave): ?><tr><td class="subtle center">No leave requests.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
    <div class="card">
        <h2 class="panel-title">My requests to HR</h2>
        <table class="tbl"><tbody>
        <?php foreach ($hr as $h): ?>
            <tr><td><?= e($h['Subject']) ?></td>
                <td><span class="chip <?= (int) $h['StateID'] >= 3 ? 'present' : 'pending' ?>">
                    <?= e(\App\Repositories\HrRequestRepository::STATE_LABELS[(int) $h['StateID']] ?? '') ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$hr): ?><tr><td class="subtle center">No requests.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>
