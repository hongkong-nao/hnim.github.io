<?php
/**
 * HNIM API 入口
 * 所有接口统一返回 JSON
 */
declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  接口只吐 JSON。
 *  这三行必须在 require 业务代码「之前」执行：共享主机上 php.ini 常是
 *  display_errors=On，require 期间冒出来的 Notice / Deprecated 会直接
 *  被打到响应体最前面，前端 JSON.parse 就废了，只能报
 *  「服务器返回异常（HTTP 200）」这种没法排查的错。
 * ------------------------------------------------------------------- */
if (function_exists('ob_start')) {
    ob_start();
}
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');

require_once __DIR__ . '/app/core.php';

hnim_output_guard();

header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

hnim_require_installed();

$action = hnim_str(hnim_input('action', ''), '', 40);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/* ================================================================== *
 *  免登录接口
 * ================================================================== */

if ($action === 'login' && $method === 'POST') {
    $username = hnim_str(hnim_input('username', ''), '', 32);
    $password = (string) hnim_input('password', '');

    if ($username === '' || $password === '') {
        hnim_fail('请输入账号和密码');
    }
    if (!hnim_csrf_check(hnim_input('csrf'))) {
        hnim_fail('页面已过期，请刷新后重试');
    }

    $pdo = hnim_db();
    $u = hnim_user_by_name($username, $pdo);
    $okPass = $u && password_verify($password, (string) $u['password']);

    hnim_log_login($pdo, (int) ($u['id'] ?? 0), $okPass ? 1 : 0);

    if (!$okPass) {
        // 轻微延时，防暴力破解
        usleep(300000);
        hnim_fail('账号或密码错误');
    }
    // 被封禁的账号即使密码正确也进不来
    if ((int) ($u['banned'] ?? 0) === 1) {
        $why = trim((string) ($u['ban_reason'] ?? ''));
        hnim_fail('这个账号已被管理员封禁' . ($why !== '' ? '：' . $why : '') . '，如有疑问请联系管理员');
    }
    if (password_needs_rehash((string) $u['password'], PASSWORD_DEFAULT)) {
        $st = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $st->execute([password_hash($password, PASSWORD_DEFAULT), (int) $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    hnim_touch_online((int) $u['id'], $pdo);
    hnim_ok(['user' => hnim_user_public($u)]);
}

if ($action === 'check_username' && $method === 'GET') {
    // 注册页实时检测账号能不能用。不返回任何用户信息，只回“能不能用”。
    if (!hnim_config('app.allow_register', true)) {
        hnim_fail('管理员已关闭注册', 403);
    }
    $username = hnim_str(hnim_input('username', ''), '', 32);
    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
        hnim_ok(['available' => false, 'reason' => '账号需为 3-32 位字母、数字或下划线']);
    }
    if (hnim_user_by_name($username, hnim_db())) {
        hnim_ok(['available' => false, 'reason' => '该账号已被注册']);
    }
    hnim_ok(['available' => true, 'reason' => '']);
}

if ($action === 'verify_send' && $method === 'POST') {
    // 注册时点「获取验证码」走这里。注册本身必须过邮件验证，所以先发码。
    if (!hnim_config('app.allow_register', true)) {
        hnim_fail('管理员已关闭注册');
    }
    if (!hnim_csrf_check(hnim_input('csrf'))) {
        hnim_fail('页面已过期，请刷新后重试');
    }
    $why = hnim_smtp_ready();
    if ($why !== null) {
        hnim_fail('管理员还没有配置好邮件服务，暂时无法注册（' . $why . '）');
    }
    $email = hnim_str(hnim_input('email', ''), '', 128);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        hnim_fail('请填写正确的邮箱地址');
    }
    $pdo = hnim_db();
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetchColumn() !== false) {
        hnim_fail('这个邮箱已经被注册过了，换一个吧');
    }
    $r = hnim_mail_code_send($email, 'register', hnim_client_ip());
    if (!$r['ok']) {
        hnim_fail((string) ($r['error'] ?? '验证码发送失败'));
    }
    hnim_ok(['sent' => true, 'email' => $email, 'cooldown' => 60]);
}

if ($action === 'register' && $method === 'POST') {
    if (!hnim_config('app.allow_register', true)) {
        hnim_fail('管理员已关闭注册');
    }
    if (!hnim_csrf_check(hnim_input('csrf'))) {
        hnim_fail('页面已过期，请刷新后重试');
    }
    // 注册必须走邮箱验证码：没有 SMTP 就不放行（这是需求，不是 bug）
    $why = hnim_smtp_ready();
    if ($why !== null) {
        hnim_fail('管理员还没有配置好邮件服务，暂时无法注册（' . $why . '）。请联系管理员在后台「邮件设置」里填写 SMTP。');
    }
    $username = hnim_str(hnim_input('username', ''), '', 32);
    $password = (string) hnim_input('password', '');
    $password2 = (string) hnim_input('password2', '');
    $nickname = hnim_str(hnim_input('nickname', ''), '', 32);
    $email    = hnim_str(hnim_input('email', ''), '', 128);
    $code     = hnim_str(hnim_input('code', ''), '', 12);

    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
        hnim_fail('账号需为 3-32 位字母、数字或下划线');
    }
    if (strlen($password) < 6) {
        hnim_fail('密码至少 6 位');
    }
    if (strlen($password) > 128) {
        hnim_fail('密码太长了');
    }
    if ($password2 !== '' && $password !== $password2) {
        hnim_fail('两次输入的密码不一致');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        hnim_fail('请填写正确的邮箱地址');
    }
    if ($nickname === '') {
        $nickname = $username;
    }
    $pdo = hnim_db();
    if (hnim_user_by_name($username, $pdo)) {
        hnim_fail('该账号已被注册');
    }
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetchColumn() !== false) {
        hnim_fail('这个邮箱已经被注册过了，换一个吧');
    }
    if ($code === '') {
        hnim_fail('请先点「获取验证码」并填写邮箱收到的 6 位数字');
    }
    if (!hnim_mail_code_check($email, $code, 'register')) {
        hnim_fail('验证码不正确或已过期，请重新获取');
    }
    try {
        $id = hnim_user_create($username, $password, $nickname, 'user', $pdo);
    } catch (Throwable $e) {
        // 并发注册撞车时唯一索引会报错，转成友好提示
        hnim_fail('该账号已被注册');
    }
    // 邮箱验证通过，记录下来
    $st = $pdo->prepare('UPDATE users SET email = ?, email_ok = 1 WHERE id = ?');
    $st->execute([$email, $id]);

    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    hnim_touch_online($id, $pdo);
    $u = hnim_user_by_id($id, $pdo);
    hnim_ok(['user' => hnim_user_public($u)]);
}

if ($action === 'logout') {
    $u = hnim_current_user();
    if ($u) {
        hnim_touch_offline((int) $u['id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    @session_destroy();
    if ($method === 'POST') {
        hnim_ok();
    }
    header('Location: ' . hnim_url('index.php'));
    exit;
}

if ($action === 'status') {
    hnim_ok(['installed' => true, 'name' => hnim_config('app.name', 'HNIM')]);
}

/* ================================================================== *
 *  以下接口需要登录
 * ================================================================== */

$me = hnim_current_user();
if (!$me) {
    hnim_fail('登录状态已失效，请重新登录', 401);
}
// 登录后被封禁的，这里也要拦住
if ((int) ($me['banned'] ?? 0) === 1) {
    $why = trim((string) ($me['ban_reason'] ?? ''));
    hnim_fail('这个账号已被管理员封禁' . ($why !== '' ? '：' . $why : '') . '，请联系管理员', 403);
}
$uid = (int) $me['id'];
$pdo = hnim_db();
hnim_touch_online($uid, $pdo);

switch ($action) {

    /* -------------------- 基础信息 -------------------- */
    case 'me':
        hnim_ok([
            'user'  => hnim_user_public($me),
            'csrf'  => hnim_csrf(),
            'title' => hnim_config('app.title', 'HNIM'),
            'max_mb' => hnim_config('app.max_mb', 50),
        ]);

    case 'bootstrap':
        hnim_ok([
            'user'         => hnim_user_public($me),
            'conversations' => hnim_conversation_list($uid, $pdo),
            'friends'      => hnim_friend_list($uid, $pdo),
            'requests'     => hnim_friend_requests($uid, $pdo),
            'fgroups'      => hnim_fgroup_list($uid, $pdo),
            'blocks'       => hnim_block_list($uid, $pdo),
            'favs'         => hnim_favorite_ids($uid, $pdo),
            'grequests'    => hnim_my_group_requests($uid, $pdo),
            'phrases'      => hnim_phrase_list($pdo),
            'server_time'  => time(),
        ]);

    /* -------------------- 长轮询收消息 -------------------- */
    case 'poll': {
        @set_time_limit(0);
        ignore_user_abort(true);

        $since = hnim_int(hnim_input('since', 0), 0);
        $cap   = hnim_poll_max();
        $wait  = hnim_int(hnim_input('wait', hnim_config('app.poll_seconds', 20)), 20);
        $wait  = max(0, min(25, $wait, $cap));
        $lastConv = hnim_int(hnim_input('cid', 0), 0);

        $sql = 'SELECT MAX(m.id) FROM messages m
                JOIN conversation_members cm ON cm.conversation_id = m.conversation_id
                WHERE cm.user_id = ?';
        $st = $pdo->prepare($sql);
        $st->execute([$uid]);
        $maxId = (int) $st->fetchColumn();

        $deadline = microtime(true) + $wait;
        while ($maxId <= $since && microtime(true) < $deadline) {
            usleep(500000);
            if (connection_aborted() !== CONNECTION_NORMAL) {
                break;
            }
            $st->execute([$uid]);
            $maxId = (int) $st->fetchColumn();
        }

        $payload = [
            'server_time' => time(),
            'max_id'      => $maxId,
            'conversations' => [],
            'messages'    => [],
            'typing'      => [],
            'online'      => [],
            // 服务器是单线程时不能挂起，客户端按这个毫秒数自己间隔重试
            'retry_ms'    => $cap > 0 ? 350 : 1500,
        ];

        if ($maxId > $since) {
            $payload['conversations'] = hnim_conversation_list($uid, $pdo);
            if ($lastConv > 0 && hnim_is_member($lastConv, $uid, $pdo)) {
                $rows = hnim_fetch_messages($lastConv, $since, 200, $pdo);
                foreach ($rows as $r) {
                    $payload['messages'][] = hnim_message_public($r, $uid, true);
                }
            }
        } else {
            // 没有新消息时也刷新一次会话（用于同步"正在输入"）
            $payload['conversations'] = hnim_conversation_list($uid, $pdo);
        }

        // 被踢 / 被禁言 / 全员禁言都通过这里推给前端，不用等用户点开群才知道
        if ($lastConv > 0 && hnim_is_member($lastConv, $uid, $pdo)) {
            $block = hnim_send_block($lastConv, $uid, $pdo);
            $payload['can_send']  = $block === null;
            $payload['send_lock'] = $block;
        }

        // 正在输入的成员
        $st = $pdo->prepare('SELECT t.conversation_id, t.user_id, u.nickname FROM typing_state t
                             JOIN users u ON u.id = t.user_id
                             JOIN conversation_members cm ON cm.conversation_id = t.conversation_id AND cm.user_id = t.user_id
                             WHERE t.typing_at > ? AND t.user_id <> ?');
        $st->execute([time() - 8, $uid]);
        foreach ($st->fetchAll() as $r) {
            $payload['typing'][] = [
                'conv' => (int) $r['conversation_id'],
                'user' => (int) $r['user_id'],
                'nick' => (string) $r['nickname'],
            ];
        }

        // 好友在线状态
        $st = $pdo->prepare('SELECT id, nickname, avatar, online, last_seen FROM users WHERE id <> ? AND last_seen > ?');
        $st->execute([$uid, time() - 120]);
        foreach ($st->fetchAll() as $r) {
            $payload['online'][] = [
                'id' => (int) $r['id'],
                'nickname' => (string) $r['nickname'],
                'avatar' => (string) $r['avatar'],
                'online' => hnim_is_online($r),
            ];
        }

        hnim_ok($payload);
    }

    /* -------------------- 历史消息 -------------------- */
    case 'history': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        if ($cid <= 0 || !hnim_is_member($cid, $uid, $pdo)) {
            hnim_fail('无权查看该会话');
        }
        $before = hnim_int(hnim_input('before', 0), 0);
        $rows = hnim_history($cid, $before, hnim_int(hnim_input('limit', 30), 30), $pdo);
        $out = [];
        foreach ($rows as $r) {
            $out[] = hnim_message_public($r, $uid, true);
        }
        $block = hnim_send_block($cid, $uid, $pdo);
        hnim_ok([
            'messages'  => $out,
            'has_more'  => count($rows) >= hnim_int(hnim_input('limit', 30), 30),
            'can_send'  => $block === null,
            'send_lock' => $block,
        ]);
    }

    /* -------------------- 发消息 -------------------- */
    case 'send': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid     = hnim_int(hnim_input('cid', 0), 0);
        $to      = hnim_int(hnim_input('to', 0), 0);
        $type    = hnim_str(hnim_input('type', 'text'), 'text', 16);
        $content = hnim_str(hnim_input('content', ''), '', 5000);
        $meta = hnim_input('meta', []);
        // 前端把 meta 当对象发过来时会被表单序列化成字符串，这里两种都认
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }

        // 指定好友 → 找/建单聊会话
        $sendTo = $to;
        if ($cid <= 0 && $to > 0) {
            $peer = hnim_user_by_id($to, $pdo);
            if (!$peer) {
                hnim_fail('对方不存在');
            }
            $cid = (int) hnim_single_conversation($uid, $to, $pdo)['id'];
        }
        if ($cid <= 0 || !hnim_is_member($cid, $uid, $pdo)) {
            hnim_fail('无权发送到该会话');
        }
        // 禁言 / 全员禁言在这里强制拦截，前端禁用输入框只是好看，服务端才是真拦
        $block = hnim_send_block($cid, $uid, $pdo);
        if ($block !== null) {
            hnim_fail($block, 403);
        }
        // 拉黑后双向不能发消息
        if (!$sendTo) {
            foreach (hnim_conv_member_ids($cid, $pdo) as $peerId) {
                if ($peerId !== $uid && hnim_is_blocked($uid, $peerId, $pdo)) {
                    hnim_fail('消息发送失败：你们之间已有一方把对方加入黑名单', 403);
                }
            }
        }
        if ($type === 'text' && $content === '') {
            hnim_fail('消息不能为空');
        }
        if ($type === 'image') {
            $p = hnim_str($meta['path'] ?? '', '', 200);
            if ($p === '' || !str_starts_with($p, 'uploads/img/')) {
                hnim_fail('图片路径不合法');
            }
            $content = $p;
        }
        if ($type === 'file') {
            $p = hnim_str($meta['path'] ?? '', '', 200);
            if ($p === '' || !str_starts_with($p, 'uploads/file/')) {
                hnim_fail('文件路径不合法');
            }
            if (!is_file(HNIM_ROOT . '/' . $p)) {
                hnim_fail('文件已丢失');
            }
            $content = $p;
        }

        $replyTo  = hnim_int(hnim_input('reply_to', 0), 0);
        $mentions = hnim_id_list(hnim_input('mentions', []));

        // 先自己校验一遍：回给前端的 reply_to / mentions 必须是服务端认可的值，
        // 不然前端会乐观渲染出一条根本不存在的引用。
        $replyTo  = $replyTo > 0 ? hnim_real_reply_id($cid, $replyTo, $pdo) : 0;
        $mentions = hnim_clean_mentions($cid, $mentions, $pdo);

        $id = hnim_send_message($cid, $uid, $type, $content, $meta, $pdo, $replyTo, $mentions);
        hnim_ok([
            'id'        => $id,
            'conv'      => $cid,
            'time'      => time(),
            'reply_to'  => $replyTo,
            'mentions'  => $mentions,
        ]);
    }

    case 'mark_read': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        if ($cid > 0 && hnim_is_member($cid, $uid, $pdo)) {
            hnim_mark_read($cid, $uid, $pdo);
        }
        hnim_ok(['conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'mute': {
        $cid  = hnim_int(hnim_input('cid', 0), 0);
        $mute = hnim_int(hnim_input('mute', 0), 0) ? 1 : 0;
        if (!hnim_is_member($cid, $uid, $pdo)) {
            hnim_fail('无权操作');
        }
        $st = $pdo->prepare('UPDATE conversation_members SET muted = ? WHERE conversation_id = ? AND user_id = ?');
        $st->execute([$mute, $cid, $uid]);
        hnim_ok(['conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'typing': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        if ($cid > 0 && hnim_is_member($cid, $uid, $pdo)) {
            $st = $pdo->prepare('INSERT INTO typing_state (conversation_id, user_id, typing_at) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE typing_at = VALUES(typing_at)');
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $st = $pdo->prepare('INSERT INTO typing_state (conversation_id, user_id, typing_at) VALUES (?,?,?)
                                     ON CONFLICT(conversation_id, user_id) DO UPDATE SET typing_at = excluded.typing_at');
            }
            $st->execute([$cid, $uid, time()]);
        }
        hnim_ok();
    }

    /* -------------------- 联系人 / 好友 -------------------- */
    case 'contacts':
        hnim_ok([
            'friends'  => hnim_friend_list($uid, $pdo),
            'requests' => hnim_friend_requests($uid, $pdo),
            'groups'   => hnim_fgroup_list($uid, $pdo),
            'blocks'   => hnim_block_list($uid, $pdo),
        ]);

    case 'search': {
        $kw = hnim_str(hnim_input('kw', ''), '', 32);
        if ($kw === '') {
            hnim_ok(['users' => []]);
        }
        $like = '%' . $kw . '%';
        $st = $pdo->prepare('SELECT * FROM users WHERE (username LIKE ? OR nickname LIKE ?) AND id <> ? ORDER BY nickname LIMIT 30');
        $st->execute([$like, $like, $uid]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $rid = (int) $r['id'];
            $u = hnim_user_public($r, hnim_friend_ctx($uid, $rid, $pdo));
            $u['friend_status'] = hnim_friend_status($uid, $rid, $pdo);
            $u['incoming'] = hnim_friend_status($rid, $uid, $pdo) === 0;
            $u['blocked'] = hnim_is_blocked($uid, $rid, $pdo);
            $out[] = $u;
        }
        hnim_ok(['users' => $out]);
    }

    case 'friend_add': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $fid = hnim_int(hnim_input('uid', 0), 0);
        if ($fid <= 0 || $fid === $uid) {
            hnim_fail('无效用户');
        }
        if (hnim_is_blocked($uid, $fid, $pdo)) {
            hnim_fail('对方在黑名单里，无法发送好友申请');
        }
        $st = hnim_friend_status($uid, $fid, $pdo);
        if ($st === 1) {
            hnim_fail('你们已经是好友了');
        }
        $msg = hnim_str(hnim_input('verify_msg', ''), '', 60);
        if (!hnim_friend_request($uid, $fid, $msg, $pdo)) {
            hnim_fail('发送失败');
        }
        hnim_ok(['friends' => hnim_friend_list($uid, $pdo), 'requests' => hnim_friend_requests($uid, $pdo)]);
    }

    case 'friend_accept': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $fid = hnim_int(hnim_input('uid', 0), 0);
        if (!hnim_friend_accept($uid, $fid, $pdo)) {
            hnim_fail('没有待处理的申请');
        }
        hnim_ok(['friends' => hnim_friend_list($uid, $pdo), 'requests' => hnim_friend_requests($uid, $pdo)]);
    }

    case 'friend_reject': {
        $fid = hnim_int(hnim_input('uid', 0), 0);
        // 待处理那条是 (申请人 -> 我)，拒绝要删对方向
        $st = $pdo->prepare('DELETE FROM friends WHERE user_id = ? AND friend_id = ? AND status = 0');
        $st->execute([$fid, $uid]);
        hnim_ok(['friends' => hnim_friend_list($uid, $pdo), 'requests' => hnim_friend_requests($uid, $pdo)]);
    }

    case 'friend_remove': {
        $fid = hnim_int(hnim_input('uid', 0), 0);
        hnim_friend_remove($uid, $fid, $pdo);
        hnim_ok(['friends' => hnim_friend_list($uid, $pdo), 'requests' => hnim_friend_requests($uid, $pdo)]);
    }

    case 'friend_remark': {
        $fid = hnim_int(hnim_input('uid', 0), 0);
        $remark = hnim_str(hnim_input('remark', ''), '', 32);
        $st = $pdo->prepare('UPDATE friends SET remark = ? WHERE user_id = ? AND friend_id = ?');
        $st->execute([$remark, $uid, $fid]);
        hnim_ok(['friends' => hnim_friend_list($uid, $pdo), 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    /* -------------------- 群聊 -------------------- */
    case 'group_create': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $title = hnim_str(hnim_input('title', ''), '', 32);
        if ($title === '') {
            hnim_fail('请填写群名称');
        }
        $members = hnim_id_list(hnim_input('members', []));
        // 只能拉自己认识的人进群
        $members = array_values(array_filter($members, function ($x) use ($uid, $pdo) {
            return $x !== $uid && hnim_friend_status($uid, $x, $pdo) === 1;
        }));
        $cid = hnim_group_create($uid, $title, $members, $pdo);
        hnim_ok(['cid' => $cid, 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'group_members': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        if (!hnim_is_member($cid, $uid, $pdo)) {
            hnim_fail('无权查看');
        }
        hnim_ok(['members' => hnim_group_members($cid, $pdo)]);
    }

    case 'group_add': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $fid = hnim_int(hnim_input('uid', 0), 0);
        $conv = hnim_conv_by_id($cid, $pdo);
        if (!$conv || $conv['type'] !== 'group' || !hnim_is_member($cid, $uid, $pdo)) {
            hnim_fail('无权操作');
        }
        if ($fid === $uid) {
            hnim_fail('不能邀请自己');
        }
        if (hnim_is_member($cid, $fid, $pdo)) {
            hnim_fail('对方已在群中');
        }
        // 只能拉自己认识的人进群，避免拿别人的群刷人
        if (hnim_friend_status($uid, $fid, $pdo) !== 1) {
            hnim_fail('只能邀请自己的好友');
        }
        if (!hnim_user_by_id($fid, $pdo)) {
            hnim_fail('用户不存在');
        }
        if (!hnim_group_add_member($cid, $fid, $pdo)) {
            hnim_fail('添加失败');
        }
        hnim_ok(['members' => hnim_group_members($cid, $pdo)]);
    }

    /* -------- 群资料：改群名 / 公告 / 转让 / 踢人 / 退群 / 解散 -------- */
    case 'group_info': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $info = hnim_group_info($cid, $uid, $pdo);
        if (!$info) {
            hnim_fail('无权查看');
        }
        hnim_ok(['group' => $info, 'members' => hnim_group_members($cid, $pdo)]);
    }

    case 'group_rename': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $title = hnim_str(hnim_input('title', ''), '', 32);
        if ($title === '') {
            hnim_fail('群名称不能为空');
        }
        if (!hnim_group_rename($cid, $uid, $title, $pdo)) {
            $info = hnim_group_info($cid, $uid, $pdo);
            hnim_fail($info ? '只有群主可以修改群名称' : '无权操作');
        }
        hnim_ok(['group' => hnim_group_info($cid, $uid, $pdo), 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'group_notice': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $text = hnim_str(hnim_input('text', ''), '', 200);
        if (!hnim_group_set_notice($cid, $uid, $text, $pdo)) {
            $info = hnim_group_info($cid, $uid, $pdo);
            hnim_fail($info ? '只有群主可以修改群公告' : '无权操作');
        }
        hnim_ok(['group' => hnim_group_info($cid, $uid, $pdo), 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'group_transfer': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $tid = hnim_int(hnim_input('uid', 0), 0);
        if (!hnim_group_transfer($cid, $uid, $tid, $pdo)) {
            $info = hnim_group_info($cid, $uid, $pdo);
            hnim_fail($info ? '转让群主失败，请确认对方在群中' : '无权操作');
        }
        hnim_ok(['group' => hnim_group_info($cid, $uid, $pdo), 'members' => hnim_group_members($cid, $pdo)]);
    }

    case 'group_kick': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $tid = hnim_int(hnim_input('uid', 0), 0);
        if (!hnim_group_kick($cid, $uid, $tid, $pdo)) {
            $info = hnim_group_info($cid, $uid, $pdo);
            hnim_fail($info ? '只有群主可以踢人，且不能踢自己' : '无权操作');
        }
        hnim_ok(['members' => hnim_group_members($cid, $pdo), 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'group_leave': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $info = hnim_group_info($cid, $uid, $pdo);
        if (!$info) {
            hnim_fail('你不在这个群里');
        }
        if ($info['is_owner']) {
            hnim_fail('群主不能直接退群，请先转让群主或解散群聊');
        }
        if (!hnim_group_leave($cid, $uid, $pdo)) {
            hnim_fail('退出失败');
        }
        hnim_ok(['conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'group_disband': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $info = hnim_group_info($cid, $uid, $pdo);
        if (!$info) {
            hnim_fail('无权操作');
        }
        if (!$info['is_owner']) {
            hnim_fail('只有群主可以解散群聊');
        }
        if (!hnim_group_disband($cid, $uid, $pdo)) {
            hnim_fail('解散失败');
        }
        hnim_ok(['conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    /* -------------------- 禁言 -------------------- */
    case 'group_mute': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $tid = hnim_int(hnim_input('uid', 0), 0);
        $min = hnim_int(hnim_input('minutes', 0), 0);
        $info = hnim_group_info($cid, $uid, $pdo);
        if (!$info) {
            hnim_fail('无权操作');
        }
        if (!$info['is_manager']) {
            hnim_fail('只有群主和管理员可以禁言');
        }
        if ($min !== 0 && !isset(hnim_mute_options()[$min])) {
            hnim_fail('禁言时长不合法');
        }
        if (!hnim_group_mute_member($cid, $uid, $tid, $min, $pdo)) {
            hnim_fail('禁言失败：不能禁言管理员、群主或自己');
        }
        hnim_ok([
            'group'   => hnim_group_info($cid, $uid, $pdo),
            'members' => hnim_group_members($cid, $pdo),
        ]);
    }

    case 'group_all_mute': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $on  = (int) hnim_input('on', 0) === 1;
        $info = hnim_group_info($cid, $uid, $pdo);
        if (!$info) {
            hnim_fail('无权操作');
        }
        if (!$info['is_owner']) {
            hnim_fail('只有群主可以开启或关闭全员禁言');
        }
        if (!hnim_group_all_mute($cid, $uid, $on, $pdo)) {
            hnim_fail('操作失败');
        }
        hnim_ok([
            'group'        => hnim_group_info($cid, $uid, $pdo),
            'can_send'     => hnim_send_block($cid, $uid, $pdo) === null,
            'send_lock'    => hnim_send_block($cid, $uid, $pdo),
            'conversations' => hnim_conversation_list($uid, $pdo),
        ]);
    }

    /* -------------------- 群管理员 -------------------- */
    case 'group_set_admin': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $tid = hnim_int(hnim_input('uid', 0), 0);
        $on  = (int) hnim_input('on', 0) === 1;
        $info = hnim_group_info($cid, $uid, $pdo);
        if (!$info) {
            hnim_fail('无权操作');
        }
        if (!$info['is_owner']) {
            hnim_fail('只有群主可以设置管理员');
        }
        if (!hnim_group_set_admin($cid, $uid, $tid, $on, $pdo)) {
            hnim_fail('设置失败，请确认对方在群中');
        }
        hnim_ok([
            'group'   => hnim_group_info($cid, $uid, $pdo),
            'members' => hnim_group_members($cid, $pdo),
        ]);
    }

    /* -------------------- 群名片 -------------------- */
    case 'group_card': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid  = hnim_int(hnim_input('cid', 0), 0);
        $card = hnim_str(hnim_input('card', ''), '', 32);
        if (!hnim_group_set_card($cid, $uid, $card, $pdo)) {
            hnim_fail('修改失败');
        }
        hnim_ok(['members' => hnim_group_members($cid, $pdo)]);
    }

    /* -------------------- 群头像 -------------------- */
    case 'group_avatar': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $p   = hnim_str(hnim_input('path', ''), '', 200);
        if ($p !== '' && !is_file(HNIM_ROOT . '/' . $p)) {
            hnim_fail('图片不存在');
        }
        if (!hnim_group_set_avatar($cid, $uid, $p, $pdo)) {
            hnim_fail('只有群主可以修改群头像');
        }
        hnim_ok([
            'group'         => hnim_group_info($cid, $uid, $pdo),
            'conversations' => hnim_conversation_list($uid, $pdo),
        ]);
    }

    /* -------------------- 撤回消息 -------------------- */
    case 'recall': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $mid = hnim_int(hnim_input('id', 0), 0);
        if ($mid <= 0) {
            hnim_fail('参数不对');
        }
        if (!hnim_message_recall($mid, $uid, $pdo)) {
            hnim_fail('撤回失败：只能撤回 2 分钟内自己发的消息');
        }
        $convId = 0;
        $st = $pdo->prepare('SELECT conversation_id FROM messages WHERE id = ?');
        $st->execute([$mid]);
        $convId = (int) $st->fetchColumn();
        $rows = hnim_history($convId, 0, 30, $pdo);
        $out = [];
        foreach ($rows as $r) {
            $out[] = hnim_message_public($r, $uid, true);
        }
        hnim_ok(['messages' => $out]);
    }

    /* -------------------- 聊天记录搜索 -------------------- */
    case 'search_messages': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        $kw  = hnim_str(hnim_input('kw', ''), '', 64);
        if ($cid <= 0) {
            hnim_fail('参数不对');
        }
        $list = hnim_message_search($uid, $cid, $kw, hnim_int(hnim_input('limit', 40), 40), $pdo);
        hnim_ok(['results' => $list, 'keyword' => $kw]);
    }

    /* -------------------- 黑名单 -------------------- */
    case 'block_add': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $tid = hnim_int(hnim_input('uid', 0), 0);
        if ($tid <= 0) {
            hnim_fail('参数不对');
        }
        if (!hnim_block_add($uid, $tid, $pdo)) {
            hnim_fail('加入黑名单失败，可能已经在黑名单里了');
        }
        hnim_ok(['blocks' => hnim_block_list($uid, $pdo), 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'block_remove': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $tid = hnim_int(hnim_input('uid', 0), 0);
        if (!hnim_block_remove($uid, $tid, $pdo)) {
            hnim_fail('对方不在黑名单里');
        }
        hnim_ok(['blocks' => hnim_block_list($uid, $pdo)]);
    }

    /* -------------------- 好友分组 -------------------- */
    case 'group_friend_list': {
        hnim_ok(['groups' => hnim_fgroup_list($uid, $pdo)]);
    }

    case 'group_friend_create': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $name = hnim_str(hnim_input('name', ''), '', 32);
        if ($name === '') {
            hnim_fail('分组名称不能为空');
        }
        $id = hnim_fgroup_create($uid, $name, $pdo);
        if ($id <= 0) {
            hnim_fail('创建分组失败');
        }
        hnim_ok(['groups' => hnim_fgroup_list($uid, $pdo)]);
    }

    case 'group_friend_rename': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $gid  = hnim_int(hnim_input('gid', 0), 0);
        $name = hnim_str(hnim_input('name', ''), '', 32);
        if (!hnim_fgroup_rename($uid, $gid, $name, $pdo)) {
            hnim_fail('修改分组失败');
        }
        hnim_ok(['groups' => hnim_fgroup_list($uid, $pdo)]);
    }

    case 'group_friend_delete': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $gid = hnim_int(hnim_input('gid', 0), 0);
        if (!hnim_fgroup_delete($uid, $gid, $pdo)) {
            hnim_fail('分组不存在');
        }
        hnim_ok(['groups' => hnim_fgroup_list($uid, $pdo), 'friends' => hnim_friend_list($uid, $pdo)]);
    }

    case 'group_friend_move': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $fid = hnim_int(hnim_input('uid', 0), 0);
        $gid = hnim_int(hnim_input('gid', 0), 0);
        if (!hnim_friend_move($uid, $fid, $gid, $pdo)) {
            hnim_fail('移动失败');
        }
        hnim_ok(['friends' => hnim_friend_list($uid, $pdo), 'groups' => hnim_fgroup_list($uid, $pdo)]);
    }

    /* -------------------- 打开与某人的会话 -------------------- */
    case 'open_conv': {
        $fid = hnim_int(hnim_input('uid', 0), 0);
        if ($fid <= 0 || $fid === $uid) {
            hnim_fail('参数不对');
        }
        if (hnim_is_blocked($uid, $fid, $pdo)) {
            hnim_fail('你们之间已有一方把对方加入黑名单', 403);
        }
        $peer = hnim_user_by_id($fid, $pdo);
        if (!$peer) {
            hnim_fail('对方不存在');
        }
        $cid = (int) hnim_single_conversation($uid, $fid, $pdo)['id'];
        hnim_ok(['cid' => $cid, 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    /* -------------------- 个人资料 -------------------- */
    case 'profile': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $nick = hnim_str(hnim_input('nickname', ''), '', 32);
        $sign = hnim_str(hnim_input('signature', ''), '', 120);
        if ($nick === '') {
            hnim_fail('昵称不能为空');
        }
        $st = $pdo->prepare('UPDATE users SET nickname = ?, signature = ? WHERE id = ?');
        $st->execute([$nick, $sign, $uid]);
        hnim_ok(['user' => hnim_user_public(hnim_user_by_id($uid, $pdo)), 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    case 'password': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $old = (string) hnim_input('old', '');
        $new = (string) hnim_input('new', '');
        if (!password_verify($old, (string) $me['password'])) {
            hnim_fail('原密码错误');
        }
        if (strlen($new) < 6) {
            hnim_fail('新密码至少 6 位');
        }
        $st = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $st->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
        hnim_ok();
    }

    /* -------------------- 上传 -------------------- */
    case 'upload': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $kind = hnim_str(hnim_input('kind', 'file'), 'file', 10) === 'image' ? 'image' : 'file';
        if (empty($_FILES['file'])) {
            hnim_fail('没有收到文件');
        }
        $r = hnim_store_upload($_FILES['file'], $kind, $pdo);
        if (!$r['ok']) {
            hnim_fail($r['error']);
        }
        hnim_ok([
            'path' => $r['path'],
            'name' => $r['name'],
            'size' => $r['size'],
            'w'    => $r['w'] ?? 0,
            'h'    => $r['h'] ?? 0,
            'kind' => $kind,
        ]);
    }

    case 'avatar': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        if (empty($_FILES['file'])) {
            hnim_fail('没有收到文件');
        }
        $r = hnim_store_upload($_FILES['file'], 'image', $pdo);
        if (!$r['ok']) {
            hnim_fail($r['error']);
        }
        $st = $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?');
        $st->execute([$r['path'], $uid]);
        hnim_ok(['user' => hnim_user_public(hnim_user_by_id($uid, $pdo)), 'conversations' => hnim_conversation_list($uid, $pdo)]);
    }

    /* -------------------- 下载 / 预览（需鉴权） -------------------- */
    case 'download': {
        $mid = hnim_int(hnim_input('m', 0), 0);
        $st = $pdo->prepare('SELECT * FROM messages WHERE id = ? LIMIT 1');
        $st->execute([$mid]);
        $m = $st->fetch();
        if (!$m || !hnim_is_member((int) $m['conversation_id'], $uid, $pdo)) {
            http_response_code(403);
            exit('无权下载');
        }
        $meta = json_decode((string) ($m['meta'] ?? ''), true);
        $meta = is_array($meta) ? $meta : [];
        $rel  = hnim_str($meta['path'] ?? ($m['content'] ?? ''), '', 200);

        $full = realpath(HNIM_ROOT . '/' . $rel);
        $base = realpath(HNIM_UPLOADS);
        if (!$full || !$base || !str_starts_with($full, $base) || !is_file($full)) {
            http_response_code(404);
            exit('文件不存在');
        }
        // 上传目录内禁止执行的脚本一律按二进制下载
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $name = hnim_str($meta['name'] ?? basename($full), basename($full), 120);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($full));
        $inline = ($m['type'] === 'image') && in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
        $disp = $inline ? 'inline' : 'attachment';
        header("Content-Disposition: {$disp}; filename*=UTF-8''" . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=600');
        readfile($full);
        exit;
    }

/* ======================================================
     *  群号 / 搜群 / 加群
     * ==================================================== */

    case 'group_search': {
        $kw = hnim_str(hnim_input('kw', ''), '', 64);
        hnim_ok(['groups' => hnim_group_search($uid, $kw, 20, $pdo), 'keyword' => $kw]);
    }

    /** 按群号直接进群：允许入就进，不允许就转成申请 */
    case 'group_join': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $gno = hnim_str(hnim_input('gno', ''), '', 16);
        $g = hnim_group_by_gno($gno, $pdo);
        if (!$g) {
            hnim_fail('群号 ' . $gno . ' 不存在', 404);
        }
        $cid = (int) $g['id'];
        if (hnim_is_member($cid, $uid, $pdo)) {
            hnim_ok(['cid' => $cid, 'joined' => true, 'conversations' => hnim_conversation_list($uid, $pdo)]);
        }
        // 免验证直接进（这是方便内网用的做法，跟 QQ 不一样）
        $reason = hnim_str(hnim_input('reason', ''), '', 120);
        if (!hnim_group_request($uid, $cid, $reason, $pdo)) {
            // 已经申请过了
            hnim_ok([
                'cid'       => $cid,
                'joined'    => false,
                'requested' => true,
                'grequests' => hnim_my_group_requests($uid, $pdo),
            ]);
        }
        hnim_ok([
            'cid'       => $cid,
            'joined'    => false,
            'requested' => true,
            'grequests' => hnim_my_group_requests($uid, $pdo),
        ]);
    }

    case 'group_request_list': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        if ($cid <= 0 || !hnim_is_member($cid, $uid, $pdo)) {
            hnim_fail('无权查看');
        }
        hnim_ok(['requests' => hnim_is_manager($cid, $uid, $pdo) ? hnim_group_requests($cid, $pdo) : []]);
    }

    case 'group_request_handle': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $rid = hnim_int(hnim_input('rid', 0), 0);
        $ok  = hnim_int(hnim_input('accept', 0), 0) === 1;
        $why = hnim_group_request_handle($rid, $uid, $ok, $pdo);
        if ($why !== '') {
            hnim_fail($why);
        }
        $cid = hnim_int(hnim_input('cid', 0), 0);
        hnim_ok([
            'handled'      => $ok,      // 注意：不能用 'ok'，会覆盖 hnim_ok() 的成功标记
            'requests'      => $cid > 0 && hnim_is_manager($cid, $uid, $pdo) ? hnim_group_requests($cid, $pdo) : [],
            'conversations' => hnim_conversation_list($uid, $pdo),
        ]);
    }

    /* ======================================================
     *  收藏
     * ==================================================== */

    case 'favorites': {
        hnim_ok(['favorites' => hnim_favorite_list($uid, 100, $pdo), 'ids' => hnim_favorite_ids($uid, $pdo)]);
    }

    case 'favorite_add': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $mid = hnim_int(hnim_input('mid', 0), 0);
        if ($mid <= 0) {
            hnim_fail('参数不对');
        }
        if (!hnim_favorite_add($uid, $mid, $pdo)) {
            hnim_fail('收藏失败，这条消息可能已经被删除了');
        }
        hnim_ok(['ids' => hnim_favorite_ids($uid, $pdo)]);
    }

    case 'favorite_remove': {
        if (!hnim_csrf_check(hnim_input('csrf'))) {
            hnim_fail('页面已过期，请刷新后重试');
        }
        $mid = hnim_int(hnim_input('mid', 0), 0);
        hnim_favorite_remove($uid, $mid, $pdo);
        hnim_ok(['ids' => hnim_favorite_ids($uid, $pdo)]);
    }

    /* ======================================================
     *  群文件（把会话里发过的图片 / 文件列出来）
     * ==================================================== */

    case 'conv_files': {
        $cid = hnim_int(hnim_input('cid', 0), 0);
        if ($cid <= 0 || !hnim_is_member($cid, $uid, $pdo)) {
            hnim_fail('无权查看');
        }
        $limit = max(1, min(200, hnim_int(hnim_input('limit', 60), 60)));
        $st = $pdo->prepare(
            "SELECT m.*, u.nickname, u.avatar AS uavatar, cm.card AS sender_card
             FROM messages m
             LEFT JOIN users u ON u.id = m.sender_id
             LEFT JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = m.sender_id
             WHERE m.conversation_id = ? AND m.type IN ('image','file') AND m.recalled_at = 0
             ORDER BY m.id DESC
             LIMIT {$limit}"
        );
        $st->execute([$cid]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $pub = hnim_message_public($r, $uid, false);
            $meta = is_array($pub['meta']) ? $pub['meta'] : [];
            $pub['name'] = (string) ($meta['name'] ?? basename((string) $pub['content']));
            $pub['size'] = (int) ($meta['size'] ?? 0);
            $out[] = $pub;
        }
        hnim_ok(['files' => $out]);
    }
    default:
        hnim_fail('未知接口：' . $action, 404);
}

/* ================================================================== */

function hnim_log_login(PDO $pdo, int $userId, int $ok): void
{
    try {
        $st = $pdo->prepare('INSERT INTO login_log (user_id, ip, ua, ok, created_at) VALUES (?,?,?,?,?)');
        $st->execute([$userId, hnim_client_ip(), hnim_ua(), $ok, time()]);
        // 只保留最近 500 条
        $pdo->exec('DELETE FROM login_log WHERE id NOT IN (SELECT id FROM login_log ORDER BY id DESC LIMIT 500)');
    } catch (Throwable $e) {
        // 日志失败不影响登录
    }
}
