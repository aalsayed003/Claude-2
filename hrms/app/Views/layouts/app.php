<?php use App\Core\Auth; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'HRMS') ?> · <?= e(\App\Core\Config::get('app.org')) ?></title>
    <link rel="stylesheet" href="<?= url('assets/app.css') ?>">
    <style>
      .subnav{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;padding:8px 20px;background:#f3f5f9;border-bottom:1px solid var(--line,#e2e6ee);font-size:14px}
      .subnav .grp{font-weight:700;color:#5a6577;text-transform:uppercase;font-size:11px;letter-spacing:.04em;margin-left:6px}
      .subnav a{color:#26313f;text-decoration:none;padding:3px 6px;border-radius:5px}
      .subnav a:hover{background:#e2e8f2}
      .subnav .sep{width:1px;height:16px;background:#cfd6e2;margin:0 2px}
    </style>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= url('dashboard') ?>">
        <span class="brand-mark">HR</span>
        <span><?= e(\App\Core\Config::get('app.name')) ?><small><?= e(\App\Core\Config::get('app.org')) ?></small></span>
    </a>
    <nav class="topnav">
        <a href="<?= url('dashboard') ?>">Dashboard</a>
        <a href="<?= url('attendance') ?>">Attendance</a>
        <?php if (Auth::atLeast('fa')): ?><a href="<?= url('payroll') ?>">Payroll</a><?php endif; ?>
        <a href="<?= url('me') ?>">My Space</a>
    </nav>
    <div class="userbox">
        <span><?= e(Auth::user()['full_name'] ?? '') ?> <em><?= e(Auth::role()) ?></em></span>
        <a class="btn-ghost" href="<?= url('logout') ?>">Logout</a>
    </div>
</header>

<div class="subnav">
    <span class="grp">Duty&nbsp;Roster</span>
    <a href="<?= url('dashboard') ?>">Dashboard</a>
    <a href="<?= url('attendance') ?>">Attendance</a>
    <a href="<?= url('overtime') ?>">Overtime</a>
    <?php if (Auth::atLeast('dept_head')): ?>
        <a href="<?= url('roster') ?>">Roster</a>
        <a href="<?= url('shifts') ?>">Shifts</a>
        <a href="<?= url('approvals') ?>">Approvals</a>
    <?php endif; ?>
    <?php if (Auth::isAdmin()): ?>
        <a href="<?= url('employees') ?>">Employees</a>
        <a href="<?= url('departments') ?>">Departments</a>
    <?php endif; ?>

    <?php if (Auth::atLeast('fa')): ?>
        <span class="sep"></span><span class="grp">Payroll</span>
        <a href="<?= url('payroll') ?>">Runs</a>
        <a href="<?= url('payroll/structures') ?>">Structures</a>
        <a href="<?= url('payroll/payslip') ?>">Payslips</a>
        <a href="<?= url('payroll/loans') ?>">Loans</a>
        <a href="<?= url('payroll/settlement') ?>">Settlement</a>
        <a href="<?= url('payroll/holds') ?>">Holds</a>
        <a href="<?= url('payroll/encashment') ?>">Encashment</a>
        <a href="<?= url('payroll/indemnity') ?>">Indemnity</a>
        <a href="<?= url('payroll/leave-provision') ?>">Leave Prov.</a>
        <a href="<?= url('payroll/wps') ?>">Bank File</a>
        <a href="<?= url('payroll/employees') ?>">HR Master</a>
    <?php endif; ?>

    <span class="sep"></span><span class="grp">Me</span>
    <a href="<?= url('me/payslips') ?>">Payslips</a>
    <a href="<?= url('me/leave') ?>">Leave</a>
    <a href="<?= url('me/cme') ?>">CME</a>
    <?php if (Auth::atLeast('mrd')): ?>
        <span class="sep"></span><span class="grp">HR&nbsp;Desk</span>
        <a href="<?= url('hr/leave') ?>">Leave</a>
        <a href="<?= url('hr/requests') ?>">Requests</a>
        <a href="<?= url('hr/cme') ?>">CME</a>
    <?php endif; ?>
</div>

<main class="container">
    <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>

    <?= $content ?>
</main>

<footer class="foot">
    <?= e(\App\Core\Config::get('app.name')) ?> ·
    <?= date('Y') ?> <?= e(\App\Core\Config::get('app.org')) ?>
</footer>
</body>
</html>
