<?php use App\Core\Auth; ?>
<div class="page-head">
    <div><h1>Welcome, <?= e(explode(' ', Auth::user()['full_name'] ?? 'there')[0]) ?></h1>
        <p class="subtle">Duty Roster &amp; Payroll · period <strong><?= e(period_label($period)) ?></strong> · today <?= date('d M Y', strtotime($today)) ?></p></div>
</div>

<div class="hgrid">
    <!-- ============ DUTY ROSTER ============ -->
    <div class="card">
        <div class="ph"><h2>Duty Roster — Today</h2>
            <a class="lnk" href="<?= url('attendance') ?>">Open Attendance →</a></div>
        <?php
          $drill = fn($m) => Auth::atLeast('dept_head')
            ? url('dashboard/list?metric=' . $m . '&date=' . $today . '&period=' . $period) : null;
          $tile = function ($label, $val, $href, $cls = '') {
              $in = '<span>' . e($label) . '</span><b>' . (int) $val . '</b>';
              echo $href ? '<a class="stat ' . $cls . '" href="' . $href . '">' . $in . '</a>'
                         : '<div class="stat ' . $cls . '">' . $in . '</div>';
          };
        ?>
        <div class="stats">
            <?php $tile('Late In', $roster['late'], $drill('late'), 'warn'); ?>
            <?php $tile('Early Out', $roster['early'], $drill('early'), 'warn'); ?>
            <?php $tile('Absent', $roster['absent'], $drill('absent'), 'danger'); ?>
            <?php $tile('Odd Punch', $roster['odd_punch'], $drill('odd'), 'warn'); ?>
            <?php $tile('Day Off', $roster['day_off'], $drill('day_off'), ''); ?>
        </div>
        <?php
          $isApprover = Auth::atLeast('dept_head');
          $isHr       = Auth::atLeast('fa');
          $certHref   = url('hr/requests?category=' . rawurlencode($salaryCertCategory));
        ?>
        <?php if ($isApprover || $isHr): ?>
        <div class="ph" style="margin-top:14px"><h2 style="font-size:14px">Pending Requests</h2>
            <?php if ($isApprover): ?><a class="lnk" href="<?= url('approvals') ?>">Open Approvals →</a><?php endif; ?></div>
        <div class="stats">
            <?php if ($isApprover): ?>
                <?php $tile('Roster Submissions', $pendingSchedules, url('approvals')); ?>
                <?php $tile('Change Schedule', $pendingScheduleChanges, url('approvals') . '#schedule-changes'); ?>
                <?php $tile('Attendance Corrections', $pendingCorrections, url('approvals') . '#corrections'); ?>
            <?php endif; ?>
            <?php if ($isHr): ?>
                <?php $tile('Salary Certificates', $pendingSalaryCerts, $certHref); ?>
                <?php $tile('Leave Requests', $pendingLeave, url('hr/leave')); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============ PAYROLL ============ -->
    <div class="card">
        <div class="ph"><h2>Payroll — <?= e(date('F Y', strtotime($payMonth))) ?></h2>
            <?php if (Auth::atLeast('fa')): ?><a class="lnk" href="<?= url('payroll') ?>">Open Payroll →</a><?php endif; ?></div>

        <?php if (!Auth::atLeast('fa')): ?>
            <p class="subtle">Payroll figures are visible to Finance and above.</p>
        <?php elseif ($run): ?>
            <div class="stats">
                <div class="stat"><span>Status</span><b class="txt"><?= e($runState) ?></b></div>
                <div class="stat"><span>Employees</span><b><?= (int) ($run['EmployeeCount'] ?? 0) ?></b></div>
            </div>
            <table class="mini">
                <tr><td>Total earnings</td><td class="num"><?= money($run['TotalEarnings'] ?? 0) ?></td></tr>
                <tr><td>Total deductions</td><td class="num"><?= money($run['TotalDeduction'] ?? 0) ?></td></tr>
                <tr><td><strong>Net payment</strong></td><td class="num"><strong><?= money($run['NetPayment'] ?? 0) ?></strong></td></tr>
            </table>
            <a class="btn btn-sm" href="<?= url('payroll/run?id=' . (int) $run['RunID']) ?>">View run</a>
        <?php else: ?>
            <p class="subtle">No payroll run opened for <?= e(date('F Y', strtotime($payMonth))) ?> yet.</p>
            <a class="btn btn-sm" href="<?= url('payroll') ?>">Open payroll month</a>
        <?php endif; ?>

        <div class="ph" style="margin-top:14px"><h2 style="font-size:14px">Quick links</h2></div>
        <div class="quick">
            <?php if (Auth::atLeast('fa')): ?>
                <a href="<?= url('payroll/structures') ?>">Salary Structures</a>
                <a href="<?= url('payroll/payslip') ?>">Payslips</a>
                <a href="<?= url('payroll/wps') ?>">Bank File</a>
            <?php endif; ?>
            <a href="<?= url('me/payslips') ?>">My Payslips</a>
            <a href="<?= url('me/leave') ?>">My Leave</a>
        </div>
    </div>
</div>

<style>
  .hgrid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
  @media(max-width:880px){.hgrid{grid-template-columns:1fr}}
  .card .ph{display:flex;align-items:center;justify-content:space-between;margin:0 0 12px}
  .card .ph h2{margin:0;font-size:16px;color:#0f3f6b}
  .card .lnk{font-size:13px;color:#137fc4;text-decoration:none;font-weight:600}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px}
  .stat{display:flex;flex-direction:column;gap:4px;padding:12px 14px;border:1px solid #e4e9f1;border-radius:10px;
        background:#fbfcfe;text-decoration:none;color:inherit}
  a.stat:hover{border-color:#bcd4ec;background:#f2f8fe}
  .stat span{font-size:12px;color:#6b7787}
  .stat b{font-size:24px;color:#12324f;font-variant-numeric:tabular-nums}
  .stat b.txt{font-size:18px}
  .stat.warn b{color:#c77700}.stat.danger b{color:#c0392b}
  table.mini{width:100%;border-collapse:collapse;margin:12px 0}
  table.mini td{padding:6px 2px;border-bottom:1px solid #eef1f6;font-size:14px}
  table.mini td.num{text-align:right;font-variant-numeric:tabular-nums}
  .quick{display:flex;flex-wrap:wrap;gap:8px}
  .quick a{font-size:13px;padding:6px 11px;border:1px solid #e4e9f1;border-radius:8px;color:#33414f;text-decoration:none}
  .quick a:hover{background:#f2f8fe;border-color:#bcd4ec}
</style>
