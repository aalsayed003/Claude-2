<?php use App\Core\Auth;
$cur = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
$base = trim(parse_url(url(''), PHP_URL_PATH) ?? '', '/');
if ($base !== '' && str_starts_with($cur, $base)) $cur = ltrim(substr($cur, strlen($base)), '/');
$cur = $cur === '' ? 'dashboard' : $cur;
$active = function (string $path) use ($cur) {
    $p = trim($path, '/');
    return ($cur === $p || str_starts_with($cur, $p . '/')) ? ' class="on"' : '';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'HRMS') ?> · <?= e(\App\Core\Config::get('app.org')) ?></title>
    <link rel="stylesheet" href="<?= url('assets/app.css') ?>">
    <style>
      :root{ --nav:#0f3f6b; --navbg:#ffffff; --navline:#e4e9f1; --ink:#1f2a37; --muted:#6b7787; --accent:#137fc4; }
      *{box-sizing:border-box}
      body{margin:0;background:#eef1f6;color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif}
      .hrms{display:flex;min-height:100vh}
      /* ---- sidebar ---- */
      .side{width:236px;flex:0 0 236px;background:var(--navbg);border-right:1px solid var(--navline);
            display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
      .side .logo{padding:16px 16px 12px;border-bottom:1px solid var(--navline)}
      .side .logo img{width:100%;max-width:190px;height:auto;display:block;margin:0 auto}
      .side nav{padding:8px 10px 20px;flex:1}
      .side .grp{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#93a0b2;
                 padding:14px 10px 5px}
      .side a{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:8px;color:#33414f;
              text-decoration:none;font-size:14px;font-weight:500;line-height:1.1}
      .side a:hover{background:#eef4fb;color:var(--nav)}
      .side a.on{background:var(--nav);color:#fff}
      .side a .d{width:6px;height:6px;border-radius:50%;background:#c3ccd8;flex:0 0 6px}
      .side a.on .d{background:#fff}
      /* ---- main ---- */
      .main{flex:1;min-width:0;display:flex;flex-direction:column}
      .topbar2{display:flex;align-items:center;justify-content:space-between;gap:12px;
               background:#fff;border-bottom:1px solid var(--navline);padding:11px 22px;position:sticky;top:0;z-index:5}
      .topbar2 .pg{font-size:16px;font-weight:700;color:var(--nav)}
      .topbar2 .who{display:flex;align-items:center;gap:12px;font-size:13px;color:var(--muted)}
      .topbar2 .who b{color:var(--ink);font-weight:600}
      .topbar2 .chip{background:#eef4fb;color:var(--nav);border-radius:999px;padding:2px 9px;font-size:11px;font-weight:600}
      .topbar2 .out{border:1px solid var(--navline);border-radius:8px;padding:5px 12px;color:#33414f;text-decoration:none;font-weight:600}
      .topbar2 .out:hover{background:#f3f6fb}
      .content{padding:22px;max-width:1180px;width:100%;margin:0 auto;flex:1}
      .foot2{padding:14px 22px;color:#9aa6b4;font-size:12px;border-top:1px solid var(--navline);background:#fff}
      @media(max-width:820px){
        .hrms{flex-direction:column}
        .side{width:100%;flex:none;height:auto;position:static;flex-direction:column;border-right:0;border-bottom:1px solid var(--navline)}
        .side nav{display:flex;flex-wrap:wrap;gap:2px}
        .side .grp{width:100%;padding:8px 10px 2px}
      }
    </style>
</head>
<body>
<div class="hrms">
    <aside class="side">
        <div class="logo">
            <a href="<?= url('dashboard') ?>"><img src="<?= url('assets/assh-logo.jpg') ?>" alt="Al Salam Specialist Hospital"></a>
        </div>
        <nav>
            <div class="grp">Overview</div>
            <a href="<?= url('dashboard') ?>"<?= $active('dashboard') ?>><span class="d"></span> Dashboard</a>

            <div class="grp">Duty Roster</div>
            <a href="<?= url('attendance') ?>"<?= $active('attendance') ?>><span class="d"></span> Attendance</a>
            <a href="<?= url('overtime') ?>"<?= $active('overtime') ?>><span class="d"></span> Overtime</a>
            <?php if (Auth::atLeast('dept_head')): ?>
                <a href="<?= url('roster') ?>"<?= $active('roster') ?>><span class="d"></span> Duty Roster</a>
                <a href="<?= url('shifts') ?>"<?= $active('shifts') ?>><span class="d"></span> Shifts</a>
                <a href="<?= url('approvals') ?>"<?= $active('approvals') ?>><span class="d"></span> Approvals</a>
            <?php endif; ?>
            <?php if (Auth::isAdmin()): ?>
                <a href="<?= url('employees') ?>"<?= $active('employees') ?>><span class="d"></span> Employees</a>
                <a href="<?= url('departments') ?>"<?= $active('departments') ?>><span class="d"></span> Departments</a>
            <?php endif; ?>

            <?php if (Auth::atLeast('fa')): ?>
                <div class="grp">Payroll</div>
                <a href="<?= url('payroll') ?>"<?= $active('payroll') ?>><span class="d"></span> Payroll Runs</a>
                <a href="<?= url('payroll/structures') ?>"<?= $active('payroll/structure') ?>><span class="d"></span> Salary Structures</a>
                <a href="<?= url('payroll/payslip') ?>"<?= $active('payroll/payslip') ?>><span class="d"></span> Payslips</a>
                <a href="<?= url('payroll/loans') ?>"<?= $active('payroll/loans') ?>><span class="d"></span> Loans</a>
                <a href="<?= url('payroll/settlement') ?>"<?= $active('payroll/settlement') ?>><span class="d"></span> Settlement</a>
                <a href="<?= url('payroll/holds') ?>"<?= $active('payroll/holds') ?>><span class="d"></span> Salary Hold</a>
                <a href="<?= url('payroll/encashment') ?>"<?= $active('payroll/encashment') ?>><span class="d"></span> Leave Encashment</a>
                <a href="<?= url('payroll/indemnity') ?>"<?= $active('payroll/indemnity') ?>><span class="d"></span> Indemnity</a>
                <a href="<?= url('payroll/leave-provision') ?>"<?= $active('payroll/leave-provision') ?>><span class="d"></span> Leave Provision</a>
                <a href="<?= url('payroll/wps') ?>"<?= $active('payroll/wps') ?>><span class="d"></span> Bank File (WPS)</a>
                <a href="<?= url('payroll/employees') ?>"<?= $active('payroll/employees') ?>><span class="d"></span> HR Master</a>
            <?php endif; ?>

            <div class="grp">My Space</div>
            <a href="<?= url('me/payslips') ?>"<?= $active('me/payslips') ?>><span class="d"></span> My Payslips</a>
            <a href="<?= url('me/leave') ?>"<?= $active('me/leave') ?>><span class="d"></span> My Leave</a>
            <a href="<?= url('me/cme') ?>"<?= $active('me/cme') ?>><span class="d"></span> My CME</a>

            <?php if (Auth::atLeast('mrd')): ?>
                <div class="grp">HR Desk</div>
                <a href="<?= url('hr/leave') ?>"<?= $active('hr/leave') ?>><span class="d"></span> Leave Requests</a>
                <a href="<?= url('hr/requests') ?>"<?= $active('hr/requests') ?>><span class="d"></span> HR Requests</a>
                <a href="<?= url('hr/cme') ?>"<?= $active('hr/cme') ?>><span class="d"></span> CME Tracking</a>
            <?php endif; ?>
        </nav>
    </aside>

    <div class="main">
        <div class="topbar2">
            <div class="pg"><?= e($title ?? 'Dashboard') ?></div>
            <div class="who">
                <span><b><?= e(Auth::user()['full_name'] ?? '') ?></b></span>
                <span class="chip"><?= e(Auth::role()) ?></span>
                <a class="out" href="<?= url('logout') ?>">Logout</a>
            </div>
        </div>

        <div class="content">
            <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
                <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
            <?php endforeach; unset($_SESSION['flash']); ?>
            <?= $content ?>
        </div>

        <footer class="foot2">
            <?= e(\App\Core\Config::get('app.name')) ?> · <?= date('Y') ?> <?= e(\App\Core\Config::get('app.org')) ?>
        </footer>
    </div>
</div>
</body>
</html>
