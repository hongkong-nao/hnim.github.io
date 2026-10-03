<?php
/**
 * HNIM 主界面（QQ 风格）
 */
declare(strict_types=1);

require_once __DIR__ . '/app/core.php';

hnim_require_installed();

hnim_session_start();
$me = hnim_current_user();
$hideChrome = hnim_str(hnim_input('chrome', ''), '', 4) === '0';

$pageTitle = (string) hnim_config('app.title', 'HNIM 局域网聊天');
$boot = [
    'installed' => true,
    'logged_in' => (bool) $me,
    'csrf'      => hnim_csrf(),
    'max_mb'    => (int) hnim_config('app.max_mb', 50),
    'title'     => $pageTitle,
    'version'   => HNIM_VERSION,
    'base'      => hnim_base_url(),
    'api'       => hnim_url('api.php'),
    'user'      => $me ? hnim_user_public($me) : null,
    'conversations' => $me ? hnim_conversation_list((int) $me['id']) : [],
    'friends'   => $me ? hnim_friend_list((int) $me['id']) : [],
    'requests'  => $me ? hnim_friend_requests((int) $me['id']) : [],
    'favs'      => $me ? hnim_favorite_ids((int) $me['id']) : [],
    'grequests' => $me ? hnim_my_group_requests((int) $me['id']) : [],
    'phrases'   => hnim_phrase_list(),
    'server_time' => time(),
    'smtp_ready' => hnim_smtp_ready() === null,
    'allow_register' => (bool) hnim_config('app.allow_register', true),
];
$nav = (string) hnim_input('view', $me ? 'app' : 'login');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(hnim_csrf(), ENT_QUOTES) ?>">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%2312b7f5'/><path d='M9 20c0 4 3 7 7 7s7-3 7-7V11l-4-3h-6z' fill='white'/></svg>">
<link rel="stylesheet" href="<?= hnim_asset('css/qq.css') ?>">
</head>
<body class="<?= $hideChrome ? 'no-chrome' : '' ?><?= $me ? '' : ' is-login' ?>">

<?php if (!$me): ?>
<!-- ================= 登录 / 注册 ================= -->
<div class="login-wrap">
  <div class="login-bg"></div>
  <div class="login-box">
    <div class="login-head">
      <div class="logo">H</div>
      <div>
        <h1>HNIM</h1>
        <p><?= htmlspecialchars($pageTitle) ?></p>
      </div>
    </div>

    <div class="login-avatar"><span id="loginInitial">H</span></div>

    <form id="loginForm" autocomplete="on">
      <div class="ipt">
        <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z"/></svg>
        <input type="text" id="lgUser" name="username" placeholder="账号" autocomplete="username" value="<?= htmlspecialchars((string) ($_COOKIE['hnim_last_user'] ?? ''), ENT_QUOTES) ?>">
      </div>
      <div class="ipt">
        <svg viewBox="0 0 24 24"><path d="M17 8h-1V6a4 4 0 10-8 0v2H7a2 2 0 00-2 2v9a2 2 0 002 2h10a2 2 0 002-2v-9a2 2 0 00-2-2zM10 6a2 2 0 114 0v2h-4V6z"/></svg>
        <input type="password" id="lgPass" name="password" placeholder="密码" autocomplete="current-password">
        <button type="button" class="eye" id="lgEye" title="显示密码">
          <svg viewBox="0 0 24 24"><path d="M12 5c-5 0-9 4.5-9 7s4 7 9 7 9-4.5 9-7-4-7-9-7zm0 12a5 5 0 110-10 5 5 0 010 10zm0-2a3 3 0 100-6 3 3 0 000 6z"/></svg>
        </button>
      </div>
      <div class="login-row">
        <label class="ck"><input type="checkbox" id="lgRemember" checked><span>记住账号</span></label>
        <a href="#" id="lgToReg">注册账号</a>
      </div>
      <div class="login-err" id="loginErr"></div>
      <button class="btn-primary" type="submit" id="lgBtn">登 录</button>
    </form>

    <form id="regForm" class="hidden">
      <div class="ipt">
        <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z"/></svg>
        <input type="text" id="rgUser" placeholder="账号：3-32 位字母/数字/下划线">
        <span class="reg-tip" id="rgUserTip"></span>
      </div>
      <div class="ipt">
        <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 100-8 4 4 0 000 8zm0 2c-4 0-8 2-8 5v1h16v-1c0-3-4-5-8-5z"/></svg>
        <input type="text" id="rgNick" placeholder="昵称（可留空，默认同账号）">
      </div>
      <div class="ipt">
        <svg viewBox="0 0 24 24"><path d="M4 5h16a1 1 0 011 1v12a1 1 0 01-1 1H4a1 1 0 01-1-1V6a1 1 0 011-1zm1.6 2L12 11l6.4-4H5.6zM5 8.4V17h14V8.4l-6.4 4a1 1 0 01-1.2 0L5 8.4z"/></svg>
        <input type="text" id="rgEmail" placeholder="邮箱：用于接收注册验证码" autocomplete="email">
        <span class="reg-tip" id="rgMailTip"></span>
      </div>
      <div class="ipt code-row">
        <svg viewBox="0 0 24 24"><path d="M17 8h-1V6a4 4 0 10-8 0v2H7a2 2 0 00-2 2v9a2 2 0 002 2h10a2 2 0 002-2v-9a2 2 0 00-2-2zM10 6a2 2 0 114 0v2h-4V6zm2 6a1.5 1.5 0 011 2.6V17h-2v-2.4A1.5 1.5 0 0112 12z"/></svg>
        <input type="text" id="rgCode" placeholder="邮箱验证码（6 位数字）" inputmode="numeric" maxlength="8" autocomplete="off">
        <button type="button" class="btn-code" id="rgCodeBtn">获取验证码</button>
      </div>
      <?php if (!$boot['smtp_ready']): ?>
      <div class="reg-warn">
        管理员还没有配置好邮件服务，暂时不能注册。请联系管理员在后台 <code>admin.php → 邮件设置</code> 里填写 SMTP。
      </div>
      <?php endif; ?>
      <div class="ipt">
        <svg viewBox="0 0 24 24"><path d="M17 8h-1V6a4 4 0 100-8 4 4 0 000-8z"/></svg>
        <input type="password" id="rgPass" placeholder="密码：至少 6 位">
        <button type="button" class="eye" id="rgEye" title="显示密码">
          <svg viewBox="0 0 24 24"><path d="M12 5c-5 0-9 4.5-9 7s4 7 9 7 9-4.5 9-7-4-7-9-7zm0 12a5 5 0 110-10 5 5 0 010 10zm0-2a3 3 0 100-6 3 3 0 000 6z"/></svg>
        </button>
        <span class="reg-tip" id="rgPwTip"></span>
      </div>
      <div class="ipt">
        <svg viewBox="0 0 24 24"><path d="M17 8h-1V6a4 4 0 100-8 4 4 0 000-8z"/></svg>
        <input type="password" id="rgPass2" placeholder="确认密码">
      </div>
      <div class="login-err" id="regErr"></div>
      <button class="btn-primary" type="submit" id="rgBtn">注 册 并 登 录</button>
      <div class="login-row center"><a href="#" id="rgToLogin">← 返回登录</a></div>
    </form>

    <div class="login-foot">
      <span id="verText">v<?= htmlspecialchars(HNIM_VERSION) ?></span>
      <span class="dot">·</span>
      <span>数据保存在本机 / 你的数据库</span>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ================= 聊天主窗口 ================= -->
<div class="window" id="window">

  <?php if (!$hideChrome): ?>
  <div class="titlebar" id="titlebar">
    <div class="tb-left">
      <div class="tb-logo">H</div>
      <span class="tb-title"><?= htmlspecialchars($pageTitle) ?></span>
    </div>
    <div class="tb-right">
      <button class="tb-btn" data-act="min" title="最小化">
        <svg viewBox="0 0 12 12"><rect x="2" y="5.5" width="8" height="1.2" fill="currentColor"/></svg>
      </button>
      <button class="tb-btn" data-act="max" title="最大化">
        <svg viewBox="0 0 12 12"><rect x="2" y="2" width="8" height="8" fill="none" stroke="currentColor" stroke-width="1.2"/></svg>
      </button>
      <button class="tb-btn close" data-act="close" title="关闭">
        <svg viewBox="0 0 12 12"><path d="M2.5 2.5l7 7M9.5 2.5l-7 7" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      </button>
    </div>
  </div>
  <?php endif; ?>

  <div class="body">
    <!-- 左侧导航 -->
    <nav class="rail">
      <div class="rail-avatar" id="railAvatar" title="<?= htmlspecialchars((string) $me['nickname']) ?>"></div>
      <div class="rail-nav">
        <button class="rail-btn on" data-view="chat" title="消息">
          <svg viewBox="0 0 24 24"><path d="M4 4h16a1 1 0 011 1v11a1 1 0 01-1 1H9l-5 4V5a1 1 0 011-1z"/></svg>
          <i class="dot-badge hidden" id="railUnread"></i>
        </button>
        <button class="rail-btn" data-view="contacts" title="联系人">
          <svg viewBox="0 0 24 24"><path d="M12 11a4 4 0 100-8 4 4 0 000 8zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5z"/></svg>
        </button>
        <button class="rail-btn" data-view="groups" title="群聊">
          <svg viewBox="0 0 24 24"><path d="M9 11a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm8 1a3 3 0 100-6 3 3 0 000 6zM9 13c-3.3 0-6 1.8-6 4v2h9.5c-.3-.7-.5-1.4-.5-2.2 0-1.5.6-2.8 1.6-3.9-.9-.5-1.9-.8-3.1-.8zM17 15c-2.8 0-5 1.6-5 3.5S14.2 22 17 22s5-1.6 5-3.5S19.8 15 17 15z"/></svg>
        </button>
        <button class="rail-btn" data-view="favorites" title="收藏">
          <svg viewBox="0 0 24 24"><path d="M12 3.6l2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L3.5 9.8l5.9-.9z"/></svg>
          <i class="dot-badge hidden" id="railFavCount"></i>
        </button>
      </div>
      <div class="rail-foot">
        <button class="rail-btn" data-view="settings" title="设置">
          <svg viewBox="0 0 24 24"><path d="M12 8a4 4 0 100 8 4 4 0 000-8zm9 4c0-.6-.05-1.2-.15-1.8l2.1-1.6-2-3.5-2.5 1a8 8 0 00-1.6-.9L16.5 2h-4l-.35 2.2c-.6.2-1.1.5-1.6.9l-2.5-1-2 3.5 2.1 1.6a8.3 8.3 0 000 1.8l-2.1 1.6 2 3.5 2.5-1c.5.4 1 .7 1.6.9L12.5 22h4l.35-2.2c.6-.2 1.1-.5 1.6-.9l2.5 1 2-3.5-2.1-1.6c.1-.6.15-1.2.15-1.8z"/></svg>
        </button>
      </div>
    </nav>

    <!-- 会话 / 联系人 列表 -->
    <section class="side">
      <div class="side-top">
        <div class="search">
          <svg viewBox="0 0 24 24"><path d="M10 4a6 6 0 104.2 10.3l4.5 4.5 1.4-1.4-4.5-4.5A6 6 0 0010 4zm0 2a4 4 0 110 8 4 4 0 010-8z"/></svg>
          <input type="text" id="sideSearch" placeholder="搜索">
        </div>
      </div>
      <div class="side-tabs" id="sideTabs">
        <button class="on" data-list="convs">消息</button>
        <button data-list="friends">好友<span class="badge hidden" id="friendBadge">0</span></button>
        <button data-list="groups">群聊</button>
      </div>
      <div class="side-list" id="sideList"></div>
    </section>

    <!-- 主区域 -->
    <main class="main" id="main">
      <div class="empty" id="mainEmpty">
        <div class="empty-logo">H</div>
        <p>选择一个会话开始聊天</p>
        <span>Enter 发送 · Shift+Enter 换行 · Ctrl+V 粘贴图片</span>
      </div>

      <!-- 聊天面板 -->
      <div class="chat hidden" id="chatPanel">
        <div class="chat-hd">
          <div class="hd-info">
            <div class="hd-name" id="chatName">—</div>
            <div class="hd-sub" id="chatSub"></div>
          </div>
          <div class="hd-ops">
            <button class="hd-btn" id="btnInviteHd" title="拉人进群">
              <svg viewBox="0 0 24 24"><path d="M15 12a4 4 0 100-8 4 4 0 000 8zm-9 1a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm9 2c-3 0-8 1.5-8 4.5V21h10v-1.5C17 16.5 18 15 21 15v-2h-3.5c.3-.6.5-1.3.5-2 0-.4 0-.7-.1-1H15z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M19 12v6M16 15h6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            </button>
            <button class="hd-btn" id="btnGroupFiles" title="群文件">
              <svg viewBox="0 0 24 24"><path d="M3 6a2 2 0 012-2h4l2 2h8a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
            </button>
            <button class="hd-btn" id="btnSearchMsg" title="搜索聊天记录">
              <svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15.5 15.5L21 21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
            <button class="hd-btn" id="btnInfo" title="聊天信息">
              <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
            </button>
            <button class="hd-btn" id="btnMute" title="消息免打扰">
              <svg viewBox="0 0 24 24"><path d="M12 2a8 8 0 018 8v5l2 3H2l2-3v-5a8 8 0 018-8zm-4 18h8a4 4 0 01-8 0z"/></svg>
            </button>
          </div>
        </div>

        <!-- 禁言 / 被踢 提示条 -->
        <div class="send-lock hidden" id="sendLock"></div>

        <div class="msg-scroll" id="msgScroll">
          <div class="load-more"><button id="btnMore" class="hidden">加载更多历史消息</button></div>
          <div id="msgList"></div>
          <div class="typing hidden" id="typingTip"></div>
        </div>

        <!-- 引用回复条 -->
        <div class="reply-bar hidden" id="replyBar">
          <div class="rb-line"></div>
          <div class="rb-body">
            <div class="rb-title" id="replyTitle">引用消息</div>
            <div class="rb-text" id="replyText"></div>
          </div>
          <button class="rb-x" id="replyX" title="取消引用">✕</button>
        </div>

        <div class="editor">
          <!-- @ 某人 时的候选列表 -->
          <div class="mention-pop hidden" id="mentionPop"></div>
          <div class="tools">
            <button class="tool" data-tool="emoji" title="表情">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r="1.3" class="fill"/><circle cx="15" cy="10" r="1.3" class="fill"/><path d="M8 14.5c1 1.4 2.4 2 4 2s3-.6 4-2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>
            <button class="tool" data-tool="image" title="发送图片">
              <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="8.5" cy="10" r="1.6" class="fill"/><path d="M4 17l5-5 4 4 3-2 4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
            </button>
            <button class="tool" data-tool="file" title="发送文件">
              <svg viewBox="0 0 24 24"><path d="M13 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-6-5z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M13 3v5h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
            </button>
            <button class="tool" data-tool="phrase" title="快捷短语">
              <svg viewBox="0 0 24 24"><path d="M4 5h16a1 1 0 011 1v9a1 1 0 01-1 1H9l-5 4V6a1 1 0 011-1z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M8 10h8M8 13h5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            </button>
            <span class="tool-line"></span>
            <span class="tool-tip">Enter 发送 / Shift+Enter 换行</span>
          </div>
          <textarea id="input" placeholder="输入消息，Enter 发送" spellcheck="false"></textarea>
          <div class="editor-foot">
            <span id="charCount">0/2000</span>
            <div class="foot-btns">
              <button class="btn-ghost" id="btnClear">清空</button>
              <button class="btn-send" id="btnSend">发送(S)</button>
            </div>
          </div>

          <!-- 快捷短语面板 -->
          <div class="emoji-panel hidden" id="emojiPanel"></div>
          <div class="phrase-panel hidden" id="phrasePanel"></div>
        </div>
      </div>

      <!-- 联系人面板 -->
      <div class="panel hidden" id="contactsPanel">
        <div class="panel-hd">
          <div class="ph-title">联系人</div>
          <div class="ph-ops">
            <button class="btn-ghost sm" id="btnAddFriend">＋ 添加好友</button>
            <button class="btn-ghost sm" id="btnCreateGroup">＋ 创建群聊</button>
            <button class="btn-ghost sm" id="btnJoinGroup">搜群号加群</button>
            <button class="btn-ghost sm" id="btnManageGroups">分组管理</button>
          </div>
        </div>
        <div class="panel-bd" id="contactsBody"></div>
      </div>

      <!-- 收藏面板 -->
      <div class="panel hidden" id="favoritesPanel">
        <div class="panel-hd">
          <div class="ph-title">我的收藏</div>
          <div class="ph-ops"><span class="muted sm" id="favCount">0 条</span></div>
        </div>
        <div class="panel-bd" id="favoritesBody"></div>
      </div>

      <!-- 设置面板 -->
      <div class="panel hidden" id="settingsPanel">
        <div class="panel-hd"><div class="ph-title">设置</div></div>
        <div class="panel-bd">
          <div class="card-set">
            <div class="set-title">外观</div>
            <div class="set-row">
              <div class="set-label">深色模式</div>
              <div class="set-ctrl">
                <label class="ck"><input type="checkbox" id="setDark"><span>夜间配色</span></label>
              </div>
            </div>
            <div class="set-row">
              <div class="set-label">紧凑模式</div>
              <div class="set-ctrl">
                <label class="ck"><input type="checkbox" id="setCompact"><span>消息显示更紧凑</span></label>
              </div>
            </div>
            <div class="set-note">设置保存在本机浏览器里，换台电脑要重新设置。</div>
          </div>
          <div class="card-set">
            <div class="set-title">个人资料</div>
            <div class="set-row">
              <div class="set-label">头像</div>
              <div class="set-ctrl">
                <div class="ava-pick" id="avaPick"></div>
                <input type="file" id="avaFile" accept="image/*" class="hidden">
                <button class="btn-ghost sm" id="btnChangeAva">更换头像</button>
              </div>
            </div>
            <div class="set-row">
              <div class="set-label">昵称</div>
              <div class="set-ctrl"><input type="text" id="setNick" maxlength="32"></div>
            </div>
            <div class="set-row">
              <div class="set-label">个性签名</div>
              <div class="set-ctrl"><input type="text" id="setSign" maxlength="120" placeholder="写点什么…"></div>
            </div>
            <div class="set-row">
              <div class="set-label">账号</div>
              <div class="set-ctrl"><span class="muted" id="setAccount"></span></div>
            </div>
            <div class="set-row">
              <div class="set-label"></div>
              <div class="set-ctrl"><button class="btn-primary sm" id="btnSaveProfile">保存资料</button><span class="ok-tip hidden" id="profileTip">已保存</span></div>
            </div>
          </div>

          <div class="card-set">
            <div class="set-title">修改密码</div>
            <div class="set-row">
              <div class="set-label">原密码</div>
              <div class="set-ctrl"><input type="password" id="pwOld" autocomplete="current-password"></div>
            </div>
            <div class="set-row">
              <div class="set-label">新密码</div>
              <div class="set-ctrl"><input type="password" id="pwNew" autocomplete="new-password" placeholder="至少 6 位"></div>
            </div>
            <div class="set-row">
              <div class="set-label"></div>
              <div class="set-ctrl"><button class="btn-primary sm" id="btnSavePw">修改密码</button><span class="ok-tip hidden" id="pwTip">已修改</span></div>
            </div>
          </div>

          <div class="card-set">
            <div class="set-title">黑名单</div>
            <div class="set-row">
              <div class="set-label">已拉黑</div>
              <div class="set-ctrl">
                <span class="muted" id="blockCount">0 人</span>
                <button class="btn-ghost sm" id="btnBlocks">查看 / 管理</button>
              </div>
            </div>
            <div class="set-note">把某人加入黑名单后，双方不能再互发消息，你也会自动解除和他的好友关系。</div>
          </div>

          <div class="card-set">
            <div class="set-title">常用功能</div>
            <div class="set-note" style="margin-bottom:10px">怕找不到？这里直接点。</div>
            <div class="ph-ops" style="flex-wrap:wrap">
              <button class="btn-ghost sm" id="navContacts">联系人</button>
              <button class="btn-ghost sm" id="navAddFriend">添加好友</button>
              <button class="btn-ghost sm" id="navCreateGroup">创建群聊</button>
              <button class="btn-ghost sm" id="navJoinGroup">搜群号加群</button>
              <button class="btn-ghost sm" id="navGroupFiles">群文件</button>
              <button class="btn-ghost sm" id="navInvite">拉人进群</button>
              <button class="btn-ghost sm" id="navAvatar">上传头像</button>
            </div>
            <div class="set-note">「群文件」「拉人进群」要先在左边 <b>群聊</b> 里选中一个群才能用。</div>
          </div>

          <div class="card-set">
            <div class="set-title">管理员</div>
            <div class="set-row">
              <div class="set-label">管理后台</div>
              <div class="set-ctrl">
                <a class="btn-ghost sm" href="<?= hnim_url('admin.php') ?>" target="_blank" rel="noopener">打开 admin.php</a>
              </div>
            </div>
            <div class="set-note">后台可以封禁 / 删除用户、配置邮件服务器、看登录与操作日志。<span class="muted">（登录账号密码在安装向导第 4 步自己设，记在 config/admin.php 里）</span></div>
          </div>

          <div class="card-set">
            <div class="set-title">关于</div>
            <div class="set-row"><div class="set-label">版本</div><div class="set-ctrl"><span class="muted">HNIM v<?= htmlspecialchars(HNIM_VERSION) ?></span></div></div>
            <div class="set-row"><div class="set-label">数据库</div><div class="set-ctrl"><span class="muted"><?= hnim_config('db.driver') === 'sqlite' ? 'SQLite 便携模式（data/hnim.sqlite）' : 'MySQL ' . htmlspecialchars((string) hnim_config('db.name')) ?></div></div>
            <div class="set-row"><div class="set-label">程序目录</div><div class="set-ctrl"><span class="muted"><?= htmlspecialchars(HNIM_ROOT) ?></span></div></div>
            <div class="set-row">
              <div class="set-label"></div>
              <div class="set-ctrl"><button class="btn-danger sm" id="btnLogout">退出登录</button></div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- 右键菜单 -->
  <div class="ctx hidden" id="ctxMenu"></div>
  <!-- 群头像上传（隐藏） -->
  <input type="file" id="gAvaFile" accept="image/*" class="hidden">
  <input type="file" id="msgImgInput" accept="image/*" class="hidden">
  <!-- 通用弹窗 -->
  <div class="modal hidden" id="modal">
    <div class="modal-mask"></div>
    <div class="modal-box">
      <div class="modal-hd"><span id="modalTitle">标题</span><button id="modalX">✕</button></div>
      <div class="modal-bd" id="modalBody"></div>
      <div class="modal-ft">
        <button class="btn-ghost sm" id="modalCancel">取消</button>
        <button class="btn-primary sm" id="modalOk">确定</button>
      </div>
    </div>
  </div>
  <!-- 图片查看 -->
  <div class="viewer hidden" id="viewer"><img id="viewerImg" alt=""><button id="viewerX">✕</button></div>
  <!-- 提示条 -->
  <div class="toast-wrap" id="toastWrap"></div>
</div>
<?php endif; ?>

<?= hnim_credit_html() ?>

<script id="boot" type="application/json"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="<?= hnim_asset('js/app.js') ?>"></script>
</body>
</html>
