<?php
/**
 * PHP 内置服务器路由脚本（php -S 使用）
 * 用法：php -S 0.0.0.0:8899 router.php
 */
declare(strict_types=1);

$root = __DIR__;
$uri  = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

// 敏感目录一律拒绝（内置服务器不支持 .htaccess）
foreach (['/app/', '/config/', '/data/'] as $blocked) {
    if (stripos($uri, $blocked) === 0) {
        http_response_code(403);
        exit("403 Forbidden\n");
    }
}

$path     = realpath($root . $uri);
$upBase   = realpath($root . '/uploads');
$upBase   = $upBase === false ? $root . '/uploads' : $upBase;
$isUploads = ($path !== false && strpos($path, $upBase . DIRECTORY_SEPARATOR) === 0);

if ($uri !== '/' && $path !== false && is_file($path) && strpos($path, $root) === 0) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    // 上传目录里的脚本一律拒绝执行
    if ($isUploads && in_array($ext, ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pl', 'py', 'cgi', 'asp', 'aspx', 'sh'], true)) {
        http_response_code(403);
        exit("403 Forbidden\n");
    }
    return false;   // 交回内置服务器直接输出（静态文件或正常执行的 PHP 入口）
}

if ($path !== false && is_dir($path) && is_file($path . '/index.php')) {
    $_SERVER['SCRIPT_NAME'] = rtrim($uri, '/') . '/index.php';
    require $path . '/index.php';
    return true;
}

// 指向一个真实文件却不存在（install.php 已删、文件名打错等）：直接 404，
// 不要 fallback 到首页 —— 否则访问已删除的 install.php 会看到「连不上数据库」，
// 让人误以为数据库坏了。
if ($path === false || !file_exists($path)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<title>404 · HNIM</title><style>'
       . 'body{margin:0;display:flex;height:100vh;align-items:center;justify-content:center;'
       . 'font:16px/1.7 "Microsoft YaHei",system-ui,sans-serif;background:#f4f6fa;color:#1f2329}'
       . 'div{text-align:center}h1{margin:0 0 6px;font-size:64px;color:#c0392b}p{margin:4px 0}'
       . 'a{color:#1a73e8}</style></head><body><div>'
       . '<h1>404</h1><p>页面不存在。</p>'
       . '<p><a href="' . htmlspecialchars((string) ($root === '' ? '/' : '/'), ENT_QUOTES, 'UTF-8') . '">回到首页</a></p>'
       . '</div></body></html>';
    return true;
}

require $root . '/index.php';
return true;
