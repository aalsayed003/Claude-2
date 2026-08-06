<?php
use App\Core\Auth;
use App\Core\Config;
use App\Services\RosterLink;
$viewRole = Config::get('payroll.roles.view', 'fa');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Payroll') ?> · <?= e(Config::get('app.org')) ?></title>
    <link rel="stylesheet" href="<?= url('assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= url('dashboard') ?>">
        <span class="brand-mark">PR</span>
        <span><?= e(Config::get('app.name')) ?><small><?= e(Config::get('app.org')) ?></small></span>
    </a>
    <nav class="topnav">
        <a href="<?= url('dashboard') ?>">Home</a>
        <?php if (Auth::atLeast($viewRole)): ?>
            <a href="<?= url('payroll') ?>">Payroll</a>
            <a href="<?= url('payroll/register') ?>">Register</a>
            <a href="<?= url('payroll/structures') ?>">Structures</a>
            <a href="<?= url('payroll/employees') ?>">Employees</a>
            <a href="<?= url('payroll/indemnity') ?>">Indemnity</a>
            <a href="<?= url('payroll/leave-provision') ?>">Leave&nbsp;Prov.</a>
            <a href="<?= url('hr/leave') ?>">HR&nbsp;Desk</a>
        <?php endif; ?>
        <a href="<?= url('me') ?>">My Self-Service</a>
    </nav>
    <div class="userbox">
        <span><?= e(Auth::user()['full_name'] ?? '') ?> <em><?= e(Auth::role()) ?></em></span>
        <a class="btn-ghost" href="<?= url('logout') ?>">Logout</a>
    </div>
</header>

<main class="container">
    <?php $l = RosterLink::status(); if (!$l['enabled']): ?>
        <div class="flash" style="background:#fff4e5;border:1px solid #f0c987;color:#8a5700">
            <strong><?= e($l['label']) ?>.</strong> <?= e($l['note']) ?>
        </div>
    <?php endif; ?>

    <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>

    <?= $content ?>
</main>

<footer class="foot">
    <?= e(Config::get('app.name')) ?> — standalone ·
    <?= date('Y') ?> <?= e(Config::get('app.org')) ?>
</footer>
</body>
</html>
