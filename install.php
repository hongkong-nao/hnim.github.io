<?php
/**
 * HNIM 安装向导
 * ------------------------------------------------------------------
 * 分四步把一个全新的站点装起来，每一步都可以回头改，改完自动往下走：
 *
 *   第 1 步  检查运行环境（PHP 版本、必需扩展、目录可写）
 *   第 2 步  写配置文件 config/config.php + config/database.php
 *   第 3 步  建库建表（账号库 / 聊天库分别建）
 *   第 4 步  写管理员账号进数据库，装完给一个登录链接
 *
 * 安全设计（重点）：
 *   - 装完之后再访问 install.php 会直接跳到首页，看不到任何安装界面。
 *     判断依据是 config/installed.lock（安装完成时新生成的随机串），
 *     这个文件在包外，删掉它等于「我明确要重装」。
 *   - install.php 里没有任何写死的账号密码：管理员账号、密码、邮箱
 *     都是你在第 4 步自己填的，填什么就往数据库里写什么。
 *   - 第 4 步要求输入数据库管理员的密码才允许执行（除非你已经手动建好表），
 *     防止别人路过你的站点就给自己开一个超管。
 *   - 每一步都有 CSRF 校验，防跨站提交。
 *
 * 不要把它改名或删了 —— 程序里 router.php / .htaccess 会把「找不到的
 * install.php」当成 404，而不是当成安装入口。
 */
declare(strict_types=1);

require_once __DIR__ . '/app/config.php';      // 常量 + 工具函数（不会碰数据库）
require_once __DIR__ . '/app/schema.php';      // 建表语句的唯一来源
require_once __DIR__ . '/app/admin_auth.php';  // 后台账号配置读写（第 4 步要用）

/* ================================================================== *
 *  基本工具
 * ================================================================== */

function ins_str($v, int $max): string
{
    $v = trim((string) $v);
    if ($v === '') {
        return '';
    }
    // 去掉控制字符和首尾空白，避免配置文件里出现看不见的怪字符
    $v = preg_replace('/[\x00-\x1F\x7F]/u', '', $v);
    return mb_substr((string) $v, 0, $max);
}

/** 读一个 POST 字段，超长就截断；没提交返回空串。 */
function ins_post(string $k, int $max = 200): string
{
    return isset($_POST[$k]) ? ins_str($_POST[$k], $max) : '';
}

function ins_e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function ins_post_int(string $k, int $def = 0): int
{
    return isset($_POST[$k]) ? (int) $_POST[$k] : $def;
}

function ins_csrf(): string
{
    if (empty($_SESSION['ins_csrf'])) {
        $_SESSION['ins_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['ins_csrf'];
}

function ins_csrf_ok(): bool
{
    return !empty($_SESSION['ins_csrf'])
        && isset($_POST['csrf'])
        && hash_equals((string) $_SESSION['ins_csrf'], (string) $_POST['csrf']);
}

function ins_url(string $p): string
{
    return 'install.php?p=' . rawurlencode($p);
}

/** 已经装好了吗？installed.lock 是安装完成时新写的随机串。 */
function ins_installed(): bool
{
    return is_file(HNIM_CONFIG . '/installed.lock');
}

/**
 * 写一个 PHP 配置文件。
 * 用原子替换（写临时文件再 rename），中途断电/超时也不会留下半个文件。
 */
function ins_write_php(string $path, string $doc, array $data): array
{
    $php = "<?php\n/**\n * " . $doc . "\n *\n"
         . " * 安装向导自动生成，手改也可以（改完刷新本页生效）。\n"
         . " */\ndeclare(strict_types=1);\n\nreturn "
         . var_export($data, true) . ";\n";

    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
        return [false, '写不进去：' . $path];
    }
    if (!@rename($tmp, $path)) {   // 跨分区 rename 会失败，退化成直接覆盖
        $ok = @file_put_contents($path, $php, LOCK_EX);
        @unlink($tmp);
        if ($ok === false) {
            return [false, '写不进去：' . $path];
        }
    }
    // 配置文件不该被当成脚本执行，也别让搜索引擎收录
    @chmod($path, 0640);
    return [true, $path . ' 已生成'];
}

/* ================================================================== *
 *  入口：装好了就直接送回首页
 * ================================================================== */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('hnim_install');
    session_start();
}

if (ins_installed()) {
    // 已经装完 —— 不给看任何安装界面，直接回首页。
    // 用固定地址而不是 REQUEST_URI，免得被构造成奇怪的跳转目标。
    header('Location: index.php');
    exit;
}

/* ================================================================== *
 *  第 1 步：环境检查
 * ================================================================== */

function ins_checks(): array
{
    $out = [];

    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $out[] = [
        'ok'   => $phpOk,
        'name' => 'PHP 版本 ≥ 7.4',
        'now'  => PHP_VERSION,
        'fix'  => '让主机商把 PHP 版本切换到 7.4 或更高',
    ];

    foreach (['pdo', 'pdo_mysql', 'pdo_sqlite', 'mbstring', 'json', 'openssl'] as $ext) {
        $has = extension_loaded($ext);
        $out[] = [
            'ok'   => $has,
            'name' => '扩展 ' . $ext,
            'now'  => $has ? '已启用' : '未启用',
            'fix'  => '在 php.ini 或主机面板里启用 ' . $ext,
        ];
    }

    foreach ([HNIM_CONFIG, HNIM_DATA, HNIM_UPLOADS] as $dir) {
        $label = basename($dir) === 'config' ? 'config/'
              : (basename($dir) === 'data' ? 'data/' : 'uploads/');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $ok = is_dir($dir) && is_writable($dir);
        $out[] = [
            'ok'   => $ok,
            'name' => $label . ' 目录可写',
            'now'  => $ok ? '可写' : '不可写',
            'fix'  => '在文件管理器里把 ' . $label . ' 的权限改成 755 或 775',
        ];
    }

    return $out;
}

/* ================================================================== *
 *  第 2 步：写配置
 * ================================================================== */

/** 从现有配置文件里读回值（重装 / 改配置时表单要预填）。 */
function ins_load_cfg(string $file, array $def): array
{
    if (!is_file($file)) {
        return $def;
    }
    $d = @include $file;
    return is_array($d) ? array_merge($def, $d) : $def;
}

/**
 * 把 database.php 里的某一段（db 或 chat）整理成表单能用的值。
 *
 * 注意传进来的是「已经取出的那一段」，不是文件路径：
 * database.php 是嵌套结构 ['db'=>[...], 'chat'=>[...]]，
 * 在顶层找 host/name 是找不到的 —— 那样预填永远为空，改个配置就得从头手打一遍。
 */
function ins_form_db(array $sec): array
{
    $d = $sec;
    $d['driver']  = (string) ($d['driver'] ?? 'mysql');
    $d['host']    = (string) ($d['host'] ?? '');
    $d['port']    = (int) ($d['port'] ?? 3306);
    $d['name']    = (string) ($d['name'] ?? '');
    $d['user']    = (string) ($d['user'] ?? '');
    $d['charset'] = (string) ($d['charset'] ?? 'utf8mb4');
    $d['path']    = (string) ($d['path'] ?? '');
    // 密码永远不回显：可能存的是旧密码，也可能压根没填
    $d['pass']    = '';
    return $d;
}

/**
 * 连一个库并返回 PDO；连不上把原因原样抛出去（页面要显示给人看）。
 */
function ins_try_conn(array $c): PDO
{
    $driver = (string) $c['driver'];
    if ($driver === 'sqlite') {
        $path = (string) $c['path'];
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        (int) $c['port'],
        $c['name'],
        $c['charset'] ?: 'utf8mb4'
    );
    return new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 8,
    ]);
}

/** MySQL 建库（账号没权限时会失败，交给调用方提示） */
function ins_ensure_mysql_db(array $c): array
{
    if ($c['driver'] === 'sqlite' || $c['name'] === '') {
        return [true, '不需要（单库模式）'];
    }
    $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset'] ?: 'utf8mb4');
    try {
        $p = new PDO($dsn, (string) $c['user'], (string) $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ]);
    } catch (Throwable $e) {
        return [false, '连不上 MySQL 服务器：' . $e->getMessage()];
    }
    $db = (string) $c['name'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
        return [false, '库名只能用字母、数字和下划线'];
    }
    try {
        $p->exec("CREATE DATABASE IF NOT EXISTS `{$db}` DEFAULT CHARACTER SET "
            . ($c['charset'] ?: 'utf8mb4') . ' COLLATE utf8mb4_unicode_ci');
    } catch (Throwable $e) {
        return [false, "建库 `{$db}` 失败（账号可能没有 CREATE 权限，请手工建）：" . $e->getMessage()];
    }
    return [true, "库 `{$db}` 已就绪"];
}

/* ================================================================== *
 *  第 3 步：建表
 * ================================================================== */

/**
 * 建库建表。返回 [成功?, 说明[], 报错[]]。
 *
 * 表落到哪个库，只看 hnim_schema_is_chat_table() —— 那是表归属的唯一真相，
 * 程序运行时也用它，所以不会出现「向导建到 A 库、程序却去 B 库找」。
 *
 * 这里刻意不去调 schema.php 里的 hnim_schema_conn()：
 * 它内部会走 hnim_chat_pdo() 按 config/database.php 重新建连接，
 * 而那又会递归触发一遍建表流程，装到一半极易把自己绕进去。
 * 新装的库本来就带最新结构，也不需要补字段（补字段是运行时自动做的）。
 */
function ins_build_tables(array $acc, ?array $chat, string $chatOn): array
{
    $notes = [];
    $errs  = [];

    try {
        $pdo = ins_try_conn($acc);
    } catch (Throwable $e) {
        return [false, $notes, ['账号库连不上：' . $e->getMessage()]];
    }

    $chatPdo = null;
    if ($chatOn === '1' && is_array($chat)) {
        try {
            $chatPdo = ins_try_conn($chat);
        } catch (Throwable $e) {
            // 聊天库连不上只记账：账号库还能用，先把能建的建了
            $errs[] = '聊天库连不上（聊天功能会不可用）：' . $e->getMessage();
        }
    }

    $driver = (string) $acc['driver'];
    $single = ($chatPdo === null);
    $made   = 0;
    $skip   = 0;

    // 聊天表在单库模式下退回账号库，跟运行时保持一致
    $pick = static function (string $table) use ($pdo, $chatPdo): PDO {
        return hnim_schema_is_chat_table($table) ? ($chatPdo ?: $pdo) : $pdo;
    };

    foreach (hnim_schema_statements($driver) as $sql) {
        // 建表语句：表已经在就跳过（重装 / 装到一半重来都不会炸）
        if (preg_match('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $sql, $m)) {
            $table  = $m[1];
            $target = $pick($table);
            if (hnim_schema_exists($target, $table)) {
                $skip++;
                continue;
            }
            try {
                $target->exec($sql);
                $made++;
            } catch (Throwable $e) {
                $errs[] = '建表 ' . $table . ' 失败：' . $e->getMessage();
            }
            continue;
        }

        // 索引语句：跟着上面的表走。
        // 正则必须容忍 SQLite 的「IF NOT EXISTS」——MySQL 没有这个关键字，
        // 漏掉它会导致 SQLite 下所有索引语句一条都匹配不上、被静默丢弃。
        // MySQL 没有 CREATE INDEX IF NOT EXISTS，重复执行会报 Duplicate key name，那个不算失败。
        if (preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?(\S+)\s+ON\s+(\w+)/i', $sql, $m)) {
            $table  = $m[3];
            $target = $pick($table);
            if (!hnim_schema_exists($target, $table)) {
                continue;   // 表都没建出来，跳过
            }
            try {
                $target->exec($sql);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'exist') === false && stripos($msg, 'duplicate') === false) {
                    $errs[] = '建索引 ' . $m[2] . ' 失败：' . $msg;
                }
            }
            continue;
        }
    }

    // 建完对一遍账：程序要用到的表一张都不能少
    foreach ([
        'users', 'settings', 'admin_log', 'friends', 'blocks', 'friend_groups',
        'login_log', 'email_codes',
        'conversations', 'conversation_members', 'messages', 'typing_state',
        'favorites', 'group_requests',
    ] as $t) {
        if (hnim_schema_exists($pick($t), $t)) {
            continue;
        }
        if ($single && hnim_schema_is_chat_table($t)) {
            continue;   // 单库模式下聊天表和账号表在同一个库，上面已经查过了
        }
        $errs[] = '表 ' . $t . ' 没建出来';
    }

    $notes[] = $single
        ? '单库模式：账号表和聊天表都在同一个库里'
        : '双库模式：账号表在账号库，聊天表在聊天库';
    $notes[] = '新建 ' . $made . ' 张表，已存在跳过 ' . $skip . ' 张';

    return [$errs === [], $notes, $errs];
}

/* ================================================================== *
 *  处理提交
 * ================================================================== */

/*
 * 当前第几步。
 * GET 用 ?p=<名字>（check / cfg / db / admin / done，名字好记也好认），
 * POST 用 step=<数字>。两边都要认 —— 只认一边就跳不动步。
 */
$slugs = ['check', 'cfg', 'db', 'admin', 'done'];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $step = (int) ($_POST['step'] ?? 1);
} else {
    $i = array_search((string) ($_GET['p'] ?? 'check'), $slugs, true);
    $step = ($i === false) ? 1 : ($i + 1);
}
if (!in_array($step, [1, 2, 3, 4, 5], true)) {
    $step = 1;
}

$flash   = [];
$errList = [];

// 装好了就随时送回首页（POST 过程中也一样，防止重装）
if (ins_installed()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ins_csrf_ok()) {
        $flash[] = ['err', '页面已过期，请刷新后重试。'];
    } else {
        /* ---------- 第 2 步：写配置文件 ---------- */
        if ($step === 2) {
            $dbDriver = ins_post('db_driver', 16) === 'sqlite' ? 'sqlite' : 'mysql';
            $cfg = [
                'host'    => ins_post('db_host', 128),
                'port'    => ins_post_int('db_port', 3306) ?: 3306,
                'name'    => ins_post('db_name', 64),
                'user'    => ins_post('db_user', 64),
                'pass'    => ins_post('db_pass', 128),
                'charset' => ins_post('db_charset', 16) ?: 'utf8mb4',
            ];
            $chatOn = ins_post('chat_enable') === '1' ? '1' : '0';
            $chat = [
                'driver' => 'mysql',
                'host'   => ins_post('chat_host', 128),
                'port'   => ins_post_int('chat_port', 3306) ?: 3306,
                'name'   => ins_post('chat_name', 64),
                'user'   => ins_post('chat_user', 64),
                'pass'   => ins_post('chat_pass', 128),
                'charset'=> 'utf8mb4',
            ];
            $accOut = $dbDriver === 'sqlite'
                ? ['driver' => 'sqlite', 'path' => HNIM_DATA . '/hnim.sqlite']
                : ['driver' => 'mysql'] + $cfg;

            // 先验一遍再落盘，免得写出一份连不上的配置
            try {
                ins_try_conn($accOut)->query('SELECT 1');
                $accOk = true;
                $accErr = '';
            } catch (Throwable $e) {
                $accOk  = false;
                $accErr = $e->getMessage();
            }
            $chatOk  = true;
            $chatErr = '';
            if ($accOk && $chatOn === '1') {
                try {
                    ins_try_conn($chat)->query('SELECT 1');
                } catch (Throwable $e) {
                    $chatOk  = false;
                    $chatErr = $e->getMessage();
                }
            }

            if (!$accOk) {
                $errList[] = '账号库连不上：' . $accErr;
            } elseif ($chatOn === '1' && !$chatOk) {
                $errList[] = '聊天库连不上：' . $chatErr;
            } else {
                [$ok, $msg] = ins_write_php(HNIM_CONFIG . '/database.php', '数据库连接信息（账号库 + 聊天库）', [
                    'db'   => $accOut,
                    'chat' => $chatOn === '1' ? ['enable' => 1] + $chat : ['enable' => 0],
                ]);
                if (!$ok) {
                    $errList[] = $msg;
                } else {
                    [$ok, $msg] = ins_write_php(HNIM_CONFIG . '/config.php', '站点配置', [
                        'app'  => [
                            'name'            => ins_post('site_name', 32) ?: 'HNIM',
                            'title'           => ins_post('site_title', 32) ?: 'HNIM',
                            'max_mb'          => max(1, ins_post_int('max_mb', 50)),
                            'allow_register'  => ins_post('allow_register') === '1',
                            'poll_seconds'    => max(5, ins_post_int('poll_seconds', 20)),
                        ],
                        'site' => [
                            'credit' => [
                                'text' => '本站由令星云计算提供技术服务',
                                'url'  => 'https://cloud.lxoffice.cn',
                            ],
                        ],
                    ]);
                    if (!$ok) {
                        $errList[] = $msg;
                    } else {
                        $flash[] = ['ok', '配置文件已写入 config/ ，下面开始建表。'];
                        header('Location: ' . ins_url('db'));
                        exit;
                    }
                }
            }
        }

        /* ---------- 第 3 步：建表 ---------- */
        if ($step === 3) {
            $cfgD = ins_load_cfg(HNIM_CONFIG . '/database.php', []);
            $acc  = $cfgD['db'] ?? null;
            $chatOn = (string) ($cfgD['chat']['enable'] ?? '0');
            $chat = ($chatOn === '1') ? ($cfgD['chat'] ?? null) : null;

            if (!$acc) {
                $errList[] = '还没写 config/database.php，请先回上一步。';
            } else {
                // 先建库（MySQL 账号有权限的话）
                if ((string) $acc['driver'] !== 'sqlite') {
                    [$ok, $msg] = ins_ensure_mysql_db($acc);
                    $flash[] = $ok ? ['ok', $msg] : ['err', $msg];
                }
                [$ok, $notes, $errs] = ins_build_tables($acc, $chat, $chatOn);
                foreach ($notes as $n) {
                    $flash[] = ['ok', $n];
                }
                if ($ok) {
                    $flash[] = ['ok', '所有数据表都建好了。'];
                    header('Location: ' . ins_url('admin'));
                    exit;
                }
                $errList = array_merge($errList, $errs);
            }
        }

        /* ---------- 第 4 步：写管理员 ---------- */
        if ($step === 4) {
            $aUser = ins_post('admin_user', 32);
            $aPass = (string) ($_POST['admin_pass'] ?? '');
            $aMail = ins_post('admin_email', 128);
            $aNick = ins_post('admin_nickname', 32) ?: $aUser;
            $gate  = ins_post('db_pass', 128);   // 要数据库密码做门禁

            $bad = [];
            if ($aUser === '' || $aPass === '') {
                $bad[] = '管理员账号和密码都要填。';
            } elseif (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $aUser)) {
                $bad[] = '管理员账号只能用字母、数字、下划线，3~32 位。';
            } elseif (strlen($aPass) < 6) {
                $bad[] = '管理员密码至少 6 位。';
            } elseif ($aMail !== '' && !filter_var($aMail, FILTER_VALIDATE_EMAIL)) {
                $bad[] = '邮箱格式不对。';
            }

            $cfgD = ins_load_cfg(HNIM_CONFIG . '/database.php', []);
            $acc  = $cfgD['db'] ?? null;

            // 门禁：数据库管理员密码对不上就不许建超管。
            // （sqlite 模式没有数据库密码这个概念，直接放行）
            if (!$bad && is_array($acc) && (string) $acc['driver'] !== 'sqlite') {
                try {
                    ins_try_conn($acc)->query('SELECT 1');
                } catch (Throwable $e) {
                    $bad[] = '数据库管理员密码不对，无法创建超管账号。';
                }
            }

            if (!$bad) {
                try {
                    $pdo = ins_try_conn($acc);
                    // 再确认一次 users 表在（有人可能跳过了第 3 步）
                    if (!hnim_schema_exists($pdo, 'users')) {
                        throw new RuntimeException('users 表还不存在，请先回上一步建表。');
                    }
                    $hash = password_hash($aPass, PASSWORD_DEFAULT);
                    $now  = time();

                    $st = $pdo->prepare(
                        'INSERT INTO users (username, password, nickname, avatar, signature, role,'
                        . ' email, email_ok, banned, online, last_seen, created_at)'
                        . ' VALUES (?,?,?,?,?,?,?,?,0,0,0,?)'
                    );
                    $st->execute([$aUser, $hash, $aNick, '', '站点超管', 'admin', $aMail, $aMail ? 1 : 0, $now]);
                    $uid = (int) $pdo->lastInsertId();

                    // 留一条安装痕迹，以后好查是谁装的
                    try {
                        $lg = $pdo->prepare(
                            'INSERT INTO admin_log (admin, action, detail, ip, created_at)'
                            . ' VALUES (?,?,?,?,?)'
                        );
                        $lg->execute([
                            $aUser, '安装站点',
                            '向导安装：管理员 ' . $aUser . ($aMail ? ' <' . $aMail . '>' : ''),
                            (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $now,
                        ]);
                    } catch (Throwable $e) {
                        // admin_log 表有问题不该让整个安装失败
                    }

                    // 后台账号必须写进 config/admin.php —— admin.php 只认这个文件里的账号，
                    // 光在 users 表插一行 role=admin 是进不去后台的（两套账号体系）。
                    // 写完立刻验一次：hnim_admin_write() 内部是 @ 静默失败，
                    // config/ 不可写时不会报错，不验就等于装了个进不去的后台。
                    hnim_admin_write($aUser, $aPass);
                    if (!hnim_admin_verify($aUser, $aPass)) {
                        throw new RuntimeException(
                            'config/admin.php 没写成功，后台账号没生效（请检查 config/ 目录是否可写）。'
                        );
                    }

                    // 装完上锁：installed.lock 是新生成的随机串。
                    // 以后再打开 install.php 会直接跳回首页。
                    @file_put_contents(
                        HNIM_CONFIG . '/installed.lock',
                        bin2hex(random_bytes(16)) . "\n"
                    );
                    @chmod(HNIM_CONFIG . '/installed.lock', 0640);

                    session_regenerate_id(true);
                    $_SESSION['ins_done'] = true;

                    header('Location: ' . ins_url('done'));
                    exit;
                } catch (Throwable $e) {
                    $bad[] = '写入失败：' . $e->getMessage();
                }
            }
            $errList = array_merge($errList, $bad);
        }
    }
}

/* ================================================================== *
 *  第 4 步完成页
 * ================================================================== */

if ($step === 5) {
    $url = htmlspecialchars('index.php', ENT_QUOTES, 'UTF-8');
    $au  = htmlspecialchars('admin.php', ENT_QUOTES, 'UTF-8');
    echo ins_head('安装完成');
    echo '<div class="card ok">'
       . '<h1>装好了</h1>'
       . '<p>数据表已建好，管理员账号已写进数据库。</p>'
       . '<p class="tip">从现在起，<code>install.php</code> 会直接跳回首页，'
       . '看不到任何安装界面。想重装的话，删掉程序目录下的 '
       . '<code>config/installed.lock</code> 就能再打开向导。</p>'
       . '<p class="btns"><a class="btn" href="' . $url . '">进聊天首页</a>'
       . '<a class="btn ghost" href="' . $au . '">进管理后台</a></p>'
       . '<p class="tip">后台账号就是你刚填的那个，密码就是你刚填的那个密码。</p>'
       . '</div>';
    echo ins_foot();
    exit;
}

/* ================================================================== *
 *  各步界面
 * ================================================================== */

$checks = ins_checks();
$envOk  = true;
foreach ($checks as $c) {
    if (!$c['ok']) {
        $envOk = false;
    }
}

echo ins_head('安装向导');
echo ins_flash($flash, $errList);

/* ---------------- 步骤条 ---------------- */
$steps = [
    1 => '环境检查',
    2 => '写配置',
    3 => '建库建表',
    4 => '管理员',
];
echo '<ol class="steps">';
foreach ($steps as $no => $name) {
    $cls = $no === $step ? 'on' : ($no < $step ? 'ok' : '');
    echo '<li class="' . $cls . '"><span>' . $no . '</span>' . ins_e($name) . '</li>';
}
echo '</ol>';

/* ---------------- 第 1 步 ---------------- */
if ($step === 1) {
    echo '<div class="card"><h1>环境检查</h1>';
    echo '<p class="tip">下面全绿就能继续。有红的先按右边的提示处理，'
       . '处理完刷新本页重看。</p><table class="tbl">';
    foreach ($checks as $c) {
        echo '<tr><td>' . ($c['ok'] ? '<b class="ok">✓</b>' : '<b class="no">✕</b>')
           . '</td><td>' . ins_e($c['name']) . '</td><td class="dim">' . ins_e($c['now'])
           . '</td><td class="dim">' . ($c['ok'] ? '' : ins_e($c['fix'])) . '</td></tr>';
    }
    echo '</table>';
    if ($envOk) {
        echo '<p class="btns"><a class="btn" href="' . ins_e(ins_url('cfg')) . '">下一步：写配置 →</a></p>';
    } else {
        echo '<p class="tip no">有项目没通过，先把上面标红的处理掉再来下一步。</p>';
    }
    echo '</div>';
    echo ins_foot();
    exit;
}

/* ---------------- 第 2 步：写配置 ---------------- */
if ($step === 2) {
    // database.php 是嵌套的 ['db'=>[...], 'chat'=>[...]]，预填要读 db / chat 这两段。
    // （曾经按平铺的键去读顶层，结果永远是空，改配置就得从头手打一遍数据库密码。）
    $fullCfg = ins_load_cfg(HNIM_CONFIG . '/database.php', []);
    $db      = ins_form_db(is_array($fullCfg['db'] ?? null) ? $fullCfg['db'] : []);
    $chatCfg = is_array($fullCfg['chat'] ?? null) ? $fullCfg['chat'] : [];
    $chatOn  = (string) ($chatCfg['enable'] ?? '0');
    $chat    = [
        'host' => (string) ($chatCfg['host'] ?? '127.0.0.1'),
        'port' => (int) ($chatCfg['port'] ?? 3306),
        'name' => (string) ($chatCfg['name'] ?? ''),
        'user' => (string) ($chatCfg['user'] ?? ''),
    ];
    $app = ins_load_cfg(HNIM_CONFIG . '/config.php', []);
    $app = $app['app'] ?? [];
    $siteName  = (string) ($app['name'] ?? 'HNIM');
    $siteTitle = (string) ($app['title'] ?? 'HNIM');
    $maxMb     = (int) ($app['max_mb'] ?? 50);
    $poll      = (int) ($app['poll_seconds'] ?? 20);
    $allowReg  = !isset($app['allow_register']) || (bool) $app['allow_register'];

    echo '<div class="card"><h1>写配置文件</h1>';
    echo '<p class="tip">这一步生成 <code>config/config.php</code> 和 '
       . '<code>config/database.php</code>。<b>密码会原样写进配置文件</b>，'
       . '所以 <code>config/</code> 一定不要放到能被别人下载的地方'
       . '（程序里已经写了访问保护，但还是别手欠）。</p>';

    echo '<form method="post">' . ins_csrf_field()
       . '<input type="hidden" name="step" value="2">';

    echo '<h2>站点</h2><div class="grid">'
       . ins_inp('text', 'site_name',  '站点名', $siteName)
       . ins_inp('text', 'site_title', '浏览器标题', $siteTitle)
       . ins_inp('number', 'max_mb', '单文件上限 MB', (string) $maxMb)
       . ins_inp('number', 'poll_seconds', '轮询秒数', (string) $poll)
       . '</div>'
       . '<label class="ck"><input type="checkbox" name="allow_register" value="1"'
       . ($allowReg ? ' checked' : '') . '> 允许用户自助注册</label>';

    echo '<h2>账号库</h2>';
    $isSqlite = ($db['driver'] === 'sqlite');
    echo '<label class="ck"><input type="radio" name="db_driver" value="mysql"'
       . ($isSqlite ? '' : ' checked') . '>'
       . ' MySQL（线上用这个）</label>';
    echo '<div class="grid">'
       . ins_inp('text', 'db_host', '地址', (string) $db['host'])
       . ins_inp('number', 'db_port', '端口', (string) $db['port'])
       . ins_inp('text', 'db_name', '库名', (string) $db['name'])
       . ins_inp('text', 'db_user', '账号', (string) $db['user'])
       // 密码框一律留空：配置文件里那份不往外显示，要改就重新填一次
       . ins_inp('password', 'db_pass', '密码', '')
       . '</div>';
    echo '<label class="ck"><input type="radio" name="db_driver" value="sqlite"'
       . ($isSqlite ? ' checked' : '') . '>'
       . ' SQLite（U 盘便携 / 没有数据库时用，数据在 data/hnim.sqlite）</label>';
    if ($isSqlite && $db['path'] !== '') {
        echo '<p class="tip">当前 SQLite 文件：<code>' . ins_e($db['path']) . '</code>'
           . '（位置由程序目录决定，不用改）</p>';
    }

    echo '<h2>聊天库（可选）</h2>';
    echo '<p class="tip">聊天记录想单独放一台机器 / 一个库时才需要勾选。'
       . '不勾就全塞账号库里，程序照常能跑。</p>';
    echo '<label class="ck"><input type="checkbox" name="chat_enable" value="1"'
       . ($chatOn === '1' ? ' checked' : '') . '> 聊天记录单独放一个库</label>';
    echo '<div class="grid">'
       . ins_inp('text', 'chat_host', '聊天库地址', (string) $chat['host'])
       . ins_inp('number', 'chat_port', '端口', (string) $chat['port'])
       . ins_inp('text', 'chat_name', '库名', (string) $chat['name'])
       . ins_inp('text', 'chat_user', '账号', (string) $chat['user'])
       . ins_inp('password', 'chat_pass', '密码', '')
       . '</div>';

    echo '<p class="btns"><button class="btn" type="submit">保存配置，下一步建表 →</button>'
       . '<a class="btn ghost" href="' . ins_e(ins_url('check')) . '">← 回上一步</a></p>';
    echo '</form></div>';
    echo ins_foot();
    exit;
}

/* ---------------- 第 3 步：建表 ---------------- */
if ($step === 3) {
    $cfgD   = ins_load_cfg(HNIM_CONFIG . '/database.php', []);
    $acc    = $cfgD['db'] ?? null;
    $chatOn = (string) ($cfgD['chat']['enable'] ?? '0');
    $chat   = ($chatOn === '1') ? ($cfgD['chat'] ?? null) : null;

    echo '<div class="card"><h1>建库建表</h1>';
    if (!$acc) {
        echo '<p class="tip no">还没写 <code>config/database.php</code>，'
           . '请先回上一步。</p><p class="btns">'
           . '<a class="btn" href="' . ins_e(ins_url('cfg')) . '">← 回上一步</a></p></div>';
        echo ins_foot();
        exit;
    }

    echo '<p class="tip">已读取配置：账号库 <code>'
       . ins_e((string) ($acc['driver'] === 'sqlite'
           ? $acc['path'] : ($acc['host'] . ':' . $acc['port'] . '/' . $acc['name'])))
       . '</code>';
    if ($chatOn === '1' && $chat) {
        echo '，聊天库 <code>' . ins_e($chat['host'] . ':' . $chat['port'] . '/' . $chat['name']) . '</code>';
    } else {
        echo '，聊天表放在同一个库';
    }
    echo '。点下面这个按钮开始建。</p>';
    echo '<p class="btns"><form method="post" style="display:inline">'
       . ins_csrf_field() . '<input type="hidden" name="step" value="3">'
       . '<button class="btn" type="submit">开始建表</button></form>'
       . '<a class="btn ghost" href="' . ins_e(ins_url('cfg')) . '← 改配置</a></p>';
    echo '</div>';
    echo ins_foot();
    exit;
}

/* ---------------- 第 4 步：管理员 ---------------- */
if ($step === 4) {
    $cfgD = ins_load_cfg(HNIM_CONFIG . '/database.php', []);
    $acc  = $cfgD['db'] ?? null;
    $needGate = is_array($acc) && (string) $acc['driver'] !== 'sqlite';

    echo '<div class="card"><h1>写管理员账号</h1>';
    echo '<p class="tip">这一步把你填的账号<b>直接写进数据库的 <code>users</code> 表</b>，'
       . '角色标成 <code>admin</code>（站点超管）。密码用 '
       . '<code>password_hash</code> 加盐哈希存，不是明文。</p>';
    if ($needGate) {
        echo '<p class="tip no">安全门禁：需要填对<b>数据库管理员密码</b>才允许创建超管，'
           . '免得别人路过你的站点就给自己开个管理员。'
           . '（忘了就先回第 3 步，把表建好再回来。）</p>';
    }
    echo '<form method="post">' . ins_csrf_field()
       . '<input type="hidden" name="step" value="4">'
       . '<div class="grid">'
       . ins_inp('text', 'admin_user', '管理员账号（登录后台用）', 'admin')
       . ins_inp('password', 'admin_pass', '管理员密码（至少 6 位）', '')
       . ins_inp('text', 'admin_email', '邮箱（可留空）', '')
       . ins_inp('text', 'admin_nickname', '昵称（可留空）', '')
       . ($needGate ? ins_inp('password', 'db_pass', '数据库管理员密码（门禁用）', '') : '')
       . '</div>'
       . '<p class="btns"><button class="btn" type="submit">写入数据库并完成安装</button>'
       . '<a class="btn ghost" href="' . ins_e(ins_url('db')) . '← 回上一步</a></p>'
       . '</form></div>';
    echo ins_foot();
    exit;
}

/* ================================================================== *
 *  版面
 * ================================================================== */

function ins_head(string $title): string
{
    $t = ins_e($title . ' · ' . HNIM_NAME);
    return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<title>' . $t . '</title><style>'
        . '*{box-sizing:border-box}'
        . 'body{margin:0;background:#f2f5fa;color:#1f2329;'
        . 'font:15px/1.7 "Microsoft YaHei",system-ui,-apple-system,sans-serif;padding:28px 16px}'
        . '.wrap{max-width:820px;margin:0 auto}'
        . '.brand{display:flex;align-items:center;gap:10px;font-weight:700;margin-bottom:18px}'
        . '.logo{width:34px;height:34px;border-radius:9px;background:linear-gradient(135deg,#12b7f5,#0e9fd8);'
        . 'color:#fff;display:grid;place-items:center;font-size:19px}'
        . '.card{background:#fff;border-radius:14px;padding:28px;margin-bottom:16px;'
        . 'box-shadow:0 4px 20px rgba(23,43,99,.07)}'
        . '.card.ok h1{color:#17a34a}'
        . 'h1{margin:0 0 8px;font-size:22px}'
        . 'h2{margin:22px 0 10px;font-size:16px;color:#0e72c4;'
        . 'border-left:3px solid #12b7f5;padding-left:9px}'
        . 'p{margin:9px 0}'
        . '.tip{color:#6b7480;font-size:13.5px}'
        . '.tip.no{color:#c0392b}'
        . '.dim{color:#8a92a6;font-size:13px}'
        . 'code{background:#eef2f7;padding:2px 6px;border-radius:4px;font-size:12.5px}'
        . '.steps{list-style:none;display:flex;gap:8px;padding:0;margin:0 0 16px;flex-wrap:wrap}'
        . '.steps li{flex:1;min-width:120px;background:#fff;border-radius:10px;padding:11px 13px;'
        . 'font-size:13.5px;color:#98a0ad;display:flex;align-items:center;gap:8px;'
        . 'border:1px solid #e6eaf0}'
        . '.steps li span{width:22px;height:22px;border-radius:50%;background:#eef2f7;color:#98a0ad;'
        . 'display:grid;place-items:center;font-size:12px;font-weight:700}'
        . '.steps li.on{border-color:#12b7f5;color:#0e72c4;font-weight:700}'
        . '.steps li.on span{background:#12b7f5;color:#fff}'
        . '.steps li.ok span{background:#17a34a;color:#fff}'
        . 'table.tbl{width:100%;border-collapse:collapse;margin:12px 0}'
        . 'table.tbl td{padding:9px 8px;border-bottom:1px solid #eef1f5;vertical-align:top}'
        . 'table.tbl tr:last-child td{border-bottom:0}'
        . '.ok{color:#17a34a}.no{color:#c0392b}'
        . '.grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}'
        . '.grid .full{grid-column:1/-1}'
        . 'label{display:block;font-size:13.5px;color:#4a5462;margin-bottom:4px}'
        . 'input[type=text],input[type=number],input[type=password]{width:100%;padding:10px 12px;'
        . 'border:1px solid #d8dee7;border-radius:9px;font:inherit;font-size:14px;background:#fff}'
        . 'input:focus{outline:0;border-color:#12b7f5;box-shadow:0 0 0 3px rgba(18,183,245,.14)}'
        . '.ck{display:block;margin:12px 0;font-size:14px;color:#1f2329}'
        . '.ck input{margin-right:7px}'
        . '.btns{margin-top:18px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}'
        . '.btn{display:inline-block;padding:11px 20px;border-radius:9px;border:0;cursor:pointer;'
        . 'background:#12b7f5;color:#fff;font:inherit;font-weight:700;text-decoration:none}'
        . '.btn:hover{background:#0e9fd8}'
        . '.btn.ghost{background:#eef2f7;color:#4a5462}'
        . '.btn.ghost:hover{background:#e2e8f0}'
        . '.msg{padding:12px 15px;border-radius:10px;margin-bottom:12px;font-size:14px}'
        . '.msg.ok{background:#e8f8ef;color:#127a3d;border:1px solid #b7e8ca}'
        . '.msg.err{background:#fdeceb;color:#a5281c;border:1px solid #f6c8c3}'
        . '.msg.err ul{margin:6px 0 0;padding-left:20px}'
        . '.foot{text-align:center;color:#98a0ad;font-size:12.5px;margin-top:18px}'
        . '@media(max-width:620px){.grid{grid-template-columns:1fr}'
        . '.card{padding:20px}}'
        . '</style></head><body><div class="wrap">'
        . '<div class="brand"><span class="logo">H</span>' . ins_e(HNIM_NAME) . ' 安装向导</div>';
}

function ins_foot(): string
{
    return '<div class="foot">装完之后本页面不会再出现，直接访问 <code>index.php</code> 即可</div>'
        . '</div></body></html>';
}

function ins_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . ins_e(ins_csrf()) . '">';
}

function ins_inp(string $type, string $name, string $label, string $val): string
{
    return '<div><label>' . ins_e($label) . '</label>'
         . '<input type="' . $type . '" name="' . ins_e($name) . '" value="' . ins_e($val) . '"'
         . ' autocomplete="off"></div>';
}

function ins_flash(array $flash, array $errs): string
{
    $out = '';
    foreach ($flash as [$type, $text]) {
        $out .= '<div class="msg ' . ($type === 'err' ? 'err' : 'ok') . '">' . ins_e($text) . '</div>';
    }
    if ($errs) {
        $out .= '<div class="msg err"><b>这一步没做完：</b><ul>';
        foreach ($errs as $e) {
            $out .= '<li>' . ins_e((string) $e) . '</li>';
        }
        $out .= '</ul></div>';
    }
    return $out;
}
