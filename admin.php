<?php
/**
 * HNIM 管理后台入口
 * ------------------------------------------------------------------
 * 只有 config/admin.php 里配置的账号能进（安装时默认 huinao / hn112233）。
 * 后台分四块：概览、用户管理、邮件设置、操作日志。
 */
declare(strict_types=1);

/* 后台的删号 / 封禁 / 改密码这些动作也走 JSON 输出，
   同样要在 require 之前把输出管起来，理由见 api.php 里的注释。 */
if (function_exists('ob_start')) {
    ob_start();
}
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

require_once __DIR__ . '/app/core.php';
require_once __DIR__ . '/app/admin_auth.php';

hnim_output_guard();

hnim_require_installed();

hnim_session_start();
hnim_admin_ensure();

$err = '';
$p   = hnim_str(hnim_input('p', 'dashboard'), 'dashboard', 20);

/* ---------------- 退出 ---------------- */
if ($p === 'logout') {
    hnim_admin_logout();
    header('Location: ' . hnim_url('admin.php'));
    exit;
}

/* ---------------- 登录 ---------------- */
if (!hnim_admin_logged() && $_SERVER['REQUEST_METHOD'] === 'POST' && hnim_input('do') === 'login') {
    $user = hnim_str(hnim_input('user', ''), '', 64);
    $pass = (string) hnim_input('pass', '');
    $key  = hnim_admin_try_key();
    $try  = $_SESSION[$key] ?? ['n' => 0, 'at' => 0];
    if ((int) $try['at'] > time() - 900 && (int) $try['n'] >= 5) {
        $err = '登录失败次数太多，请 15 分钟后再试。';
    } elseif (!hnim_csrf_check(hnim_input('csrf'))) {
        $err = '页面已过期，请刷新后重试。';
    } elseif ($user === '' || $pass === '') {
        $err = '请输入后台账号和密码。';
    } elseif (!hnim_admin_login($user, $pass)) {
        usleep(400000);
        $try = ((int) $try['at'] > time() - 900) ? $try : ['n' => 0, 'at' => time()];
        $try['n']  = (int) $try['n'] + 1;
        $try['at'] = time();
        $_SESSION[$key] = $try;
        $err = '账号或密码不对（还可以试 ' . max(0, 5 - (int) $try['n']) . ' 次）。';
    } else {
        unset($_SESSION[$key]);
        hnim_admin_log('登录后台', '');
        header('Location: ' . hnim_url('admin.php?p=dashboard'));
        exit;
    }
}

/* ---------------- 没登录就显示登录页 ---------------- */
if (!hnim_admin_logged()) {
    $csrf = hnim_csrf();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HNIM 管理后台 · 登录</title>
<link rel="stylesheet" href="<?= hnim_url('admin/style.css') ?>">
</head>
<body class="login-body">
<form class="login-card" method="post" autocomplete="off">
  <div class="login-logo">H</div>
  <h1>HNIM 管理后台</h1>
  <p class="login-sub">请使用后台管理员账号登录</p>
  <?php if ($err !== ''): ?><div class="alert err"><?= htmlspecialchars($err, ENT_QUOTES) ?></div><?php endif; ?>
  <input type="hidden" name="do" value="login">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <label>后台账号</label>
  <input type="text" name="user" placeholder="管理员账号" autofocus>
  <label>后台密码</label>
  <input type="password" name="pass" placeholder="请输入密码">
  <button class="btn primary" type="submit">登 录</button>
  <a class="back" href="<?= hnim_url('index.php') ?>">← 返回聊天</a>
</form>
</body>
</html>
    <?php
    exit;
}

/* ---------------- 已登录：加载版面 + 对应页面 ---------------- */
$pages = [
    'dashboard' => '概览',
    'users'     => '用户管理',
    'data'      => '数据清理',
    'mail'      => '邮件设置',
    'logs'      => '操作日志',
];
if (!isset($pages[$p])) {
    $p = 'dashboard';
}
$csrf = hnim_csrf();
$adminUser = hnim_admin_conf()['user'];
require __DIR__ . '/admin/layout.php';
hnim_admin_head($p === 'dashboard' ? '概览' : $pages[$p]);
require __DIR__ . '/admin/page-' . $p . '.php';
hnim_admin_foot();
