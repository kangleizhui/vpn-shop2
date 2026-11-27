<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/hiddify.php';
require __DIR__ . '/../app/payment.php';
require __DIR__ . '/../app/alipay.php';
require __DIR__ . '/../app/wechat.php';
require __DIR__ . '/../app/mailer.php';
require __DIR__ . '/../app/notify.php';
require __DIR__ . '/../app/update.php';
require __DIR__ . '/../app/theme.php';

if (!is_installed()) { header('Location: /install.php'); exit; }

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($path === '') $path = '/';

/* ================= 视图 ================= */
function page_head(string $title): void {
    $site = cfg('site_name', '云商城');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' - ' . e($site) . '</title>';
    echo '<link rel="stylesheet" href="/assets/style.css?v=' . (@filemtime(__DIR__ . '/assets/style.css') ?: '1') . '">';
    echo theme_css();
    echo '</head><body>';
}
function nav_links(): array {
    $raw = trim((string)setting('nav_links', ''));
    // 从没配置过：默认给一条站内教程入口；后台删空保存后为 []，不再出现
    if ($raw === '') return [['title' => '使用教程', 'url' => '/tutorial']];
    $j = json_decode($raw, true);
    if (!is_array($j)) return [];
    $out = [];
    foreach ($j as $it) {
        if (!is_array($it)) continue;
        $t = trim((string)($it['title'] ?? ''));
        $u = trim((string)($it['url'] ?? ''));
        if ($t === '' || $u === '') continue;
        if (!preg_match('#^(https?://|/)#i', $u)) continue;
        $out[] = ['title' => $t, 'url' => $u];
    }
    return $out;
}
function tut_dl_local(string $file): bool {
    return is_file(__DIR__ . '/downloads/' . $file);
}
function tut_dl(string $file, string $fallback): string {
    return tut_dl_local($file) ? '/downloads/' . $file : $fallback;
}
function mock_pay_box(string $orderNo): void {
    echo '<div class="paygroup"><div class="paygrouptitle">测试</div>';
    echo '<form method="post" action="/mock-pay" style="margin:6px 0">' . csrf_field();
    echo '<input type="hidden" name="order_no" value="' . e($orderNo) . '">';
    echo '<button class="btn wide" type="submit">模拟支付成功<span class="muted small" style="margin-left:8px">仅管理员 · 不扣款</span></button>';
    echo '</form></div>';
}
function server_edit_form(?array $edit, string $action = ''): void {
    echo '<div class="panel" id="srvform">' . ($action === '' ? '<h3>' . ($edit ? '编辑服务器 #' . (int)$edit['id'] : '添加服务器') . '</h3>' : '') . '<form method="post"' . ($action !== '' ? ' action="' . e($action) . '"' : '') . '>' . csrf_field();
    echo '<input type="hidden" name="act" value="' . ($edit ? 'edit' : 'add') . '">';
    if ($edit) echo '<input type="hidden" name="id" value="' . (int)$edit['id'] . '">';
    echo '<label>管理地址（完整链接，含 UUID）<input type="text" name="admin_url" required value="' . e($edit['admin_url'] ?? '') . '" placeholder="https://域名/后台路径/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></label>';
    echo '<label>管理员密码' . ($edit ? '（留空不修改）' : '') . '<input type="password" name="password" ' . ($edit ? '' : 'required') . '></label>';
    echo '<div class="row2"><label>显示名称<input type="text" name="name" value="' . e($edit['name'] ?? '') . '" placeholder="如：日本-东京"></label>';
    echo '<label>自定义地区（留空自动检测）<input type="text" name="region" value="' . e($edit['region'] ?? '') . '" placeholder="如：日本"></label></div>';
    echo '<label>面板时区（小时，留空自动检测）<input type="text" name="tz_offset_h" value="' . e($edit ? setting('server_tz_h_' . (int)$edit['id']) : '') . '" placeholder="如 8 / 9 / -5 / 3.5"></label>';
    echo '<div class="row2"><label>排序<input type="number" name="sort" value="' . (int)($edit['sort'] ?? 0) . '"></label>';
    echo '<label class="row" style="margin-top:34px"><input type="checkbox" name="enabled" value="1" ' . (!isset($edit['enabled']) || (int)$edit['enabled'] ? 'checked' : '') . '> 上架销售</label></div>';
    echo '<button class="btn">' . ($edit ? '保存修改' : '添加') . '</button></form></div>';
}
function drawer_script(): void {
    echo '<script>(function(){var b=document.getElementById("menubtn"),d=document.getElementById("drawer"),m=document.getElementById("menumask");'
       . 'if(!b||!d)return;function tog(v){var on=(v===undefined)?!d.classList.contains("on"):v;d.classList.toggle("on",on);m.classList.toggle("on",on);b.setAttribute("aria-expanded",on?"true":"false");document.body.style.overflow=on?"hidden":"";}'
       . 'b.addEventListener("click",function(){tog();});m.addEventListener("click",function(){tog(false);});'
       . 'd.querySelectorAll("a").forEach(function(a){a.addEventListener("click",function(){tog(false);});});'
       . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")tog(false);});})();</script>';
}
function nav(): void {
    $site = cfg('site_name', '云商城');
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $adminPage = is_admin() && str_starts_with($reqPath, '/admin');

    echo '<header class="nav"><div class="wrap">';
    echo '<button class="menubtn" id="menubtn" aria-label="菜单" aria-expanded="false"><i></i><i></i><i></i></button>';
    echo '<a class="brand" href="/"><span class="logo">⚡</span>' . e($site) . '</a>';
    if ($adminPage) {
        echo '<nav><a href="/">返回首页</a></nav>';
    } elseif ($reqPath === '/query') {
        echo '<nav><a href="/">套餐</a></nav>';
    } else {
        echo '<nav><a href="/query">我的订阅</a></nav>';
    }
    echo '</div></header>';

    if ($adminPage) {
        $items = [
            ['概览', '/admin'],
            ['订单', '/admin/orders'],
            ['用户', '/admin/users'],
            ['服务器', '/admin/servers'],
            ['套餐', '/admin/plans'],
            ['设置', '/admin/settings'],
        ];
        echo '<div class="mask" id="menumask"></div>';
        echo '<aside class="drawer" id="drawer"><div class="drawerhd">管理后台</div>';
        foreach ($items as $it) {
            $cls = ($reqPath === $it[1]) ? ' class="on"' : '';
            echo '<a' . $cls . ' href="' . e($it[1]) . '">' . e($it[0]) . '</a>';
        }
        echo '<div class="drawerhr"></div><a href="/">前台首页</a><a href="/admin/logout">退出登录</a></aside>';
    } else {
        echo '<div class="mask" id="menumask"></div>';
        echo '<aside class="drawer" id="drawer"><div class="drawerhd">菜单</div>';
        echo '<a href="/">首页</a>';
        echo '<a href="/query">我的订阅</a>';
        echo '<a href="/admin">管理后台</a>';
        $links = nav_links();
        if ($links) {
            echo '<div class="drawerhr"></div>';
            foreach ($links as $l) {
                $ext = (bool)preg_match('#^https?://#i', $l['url']);
                echo '<a href="' . e($l['url']) . '"' . ($ext ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . e($l['title']) . '</a>';
            }
        }
        echo '</aside>';
    }
    drawer_script();
}
function page_foot(): void {
    echo '<footer class="foot"><div class="wrap"><p>© ' . date('Y') . ' ' . e(cfg('site_name', '云商城'));
    $c = cfg('contact');
    if ($c !== '') echo ' · 客服：' . e($c);
    echo '<script src="/assets/ui.js?v=' . (@filemtime(__DIR__ . '/assets/ui.js') ?: '1') . '"></script>';
    echo '</p></div></footer><script>(function(){var ts=document.querySelectorAll("main table");for(var i=0;i<ts.length;i++){var rs=ts[i].querySelectorAll("tr"),head=null;for(var j=0;j<rs.length;j++){if(rs[j].querySelector("th")){head=rs[j];break;}}if(!head)continue;var ls=[],cs=head.children;for(var k=0;k<cs.length;k++)ls.push(cs[k].textContent.trim());for(var m=0;m<rs.length;m++){if(rs[m]===head)continue;var tds=rs[m].children;for(var n=0;n<tds.length;n++){if(ls[n])tds[n].setAttribute("data-label",ls[n]);}}}})();</script></body></html>';
}
function alerts(): void {
    foreach (take_flashes() as $f) echo '<div class="alert ' . e($f['t']) . '">' . nl2br(e($f['m'])) . '</div>';
}
function page(string $title, callable $body): void {
    page_head($title); nav();
    echo '<main class="wrap">'; alerts(); $body(); echo '</main>';
    page_foot(); exit;
}

/* ================= 前台 ================= */
if ($path === '/') {
    $plans = db()->query("SELECT * FROM plans WHERE enabled=1 ORDER BY sort,id")->fetchAll();
    $servers = all_servers(true);
    page('首页', function () use ($plans, $servers) {
        $min = $plans ? min(array_map(fn($p) => (float)$p['price'], $plans)) : 0;
        echo '<section class="hero"><h1>高速稳定 · 全球节点</h1>';
        echo '<p class="sub">一键接入，多协议支持，解锁 Google / YouTube / ChatGPT。最低 ¥' . e(money($min)) . ' 起。</p>';
        echo '<div class="chips"><span>✅ 多协议</span><span>✅ 不限速</span><span>✅ 秒开</span><span>✅ 自动开通</span></div></section>';

        if (!shop_ready()) {
            echo '<div class="alert warn">暂无可用节点，请稍后再来。</div>';
        } else {
            echo '<section class="regions"><span class="rlabel muted small">可选地区</span>';
            foreach ($servers as $s) {
                echo '<span class="region-chip">🌍 ' . e(server_region($s)) . (server_city($s) !== '' ? ' · ' . e(server_city($s)) : '') . '</span>';
            }
            echo '</section>';
        }

        echo '<section class="plans">';
        foreach ($plans as $p) {
            echo '<div class="card plan' . ($p['badge'] !== '' ? ' featured' : '') . '">';
            if ($p['badge'] !== '') echo '<div class="badge">' . e($p['badge']) . '</div>';
            echo '<h3>' . e($p['name']) . '</h3>';
            echo '<div class="price"><span class="cur">¥</span>' . e(money((float)$p['price'])) . '</div>';
            $isTop = (($p['kind'] ?? 'normal') === 'topup');
            $pDesc = $p['descr'] !== '' ? $p['descr']
                   : ($isTop ? '+' . $p['traffic_gb'] . 'GB · 不延长到期'
                             : ((int)$p['days'] > 0 ? $p['days'] . ' 天 · ' : '不限时 · ') . $p['traffic_gb'] . 'GB');
            echo '<p class="descr">' . e($pDesc) . '</p>';
            echo '<a class="btn" href="/buy?plan=' . (int)$p['id'] . '">立即购买</a>';
            echo '</div>';
        }
        echo '</section>';

        echo '<section class="steps"><h2>购买流程</h2><div class="grid3">';
        echo '<div><b>1</b><h4>选择套餐与地区</h4><p>月付 / 季付 / 年付，选你想要的节点地区。</p></div>';
        echo '<div><b>2</b><h4>扫码支付</h4><p>支付宝 / 微信，支付后自动开通。</p></div>';
        echo '<div><b>3</b><h4>导入使用</h4><p>复制订阅链接到客户端，一键更新节点。</p>'
           . '<a class="btn ghost sm step-link" href="/tutorial">查看使用教程 →</a></div>';
        echo '</div></section>';
    });
}

elseif ($path === '/buy') {
    $planId = (int)($_GET['plan'] ?? 0);
    $st = db()->prepare("SELECT * FROM plans WHERE id=? AND enabled=1");
    $st->execute([$planId]);
    $plan = $st->fetch();
    if (!$plan) { flash('套餐不存在或已下架', 'err'); redirect('/'); }
    $servers = all_servers(true);
    $scope = plan_scope((int)$plan['id']);

    page('购买 ' . $plan['name'], function () use ($plan, $servers, $scope) {
        echo '<div class="panel narrow"><h2>' . e($plan['name']) . '</h2>';
        echo '<p class="muted">' . e(days_label($plan['days'])) . ' · ' . e($plan['traffic_gb']) . 'GB · ¥' . e(money((float)$plan['price'])) . '</p>';
        echo '<form method="post" action="/order">' . csrf_field();
        echo '<input type="hidden" name="plan_id" value="' . (int)$plan['id'] . '">';

        if (($plan['kind'] ?? 'normal') === 'topup') {
            echo '<div class="alert ok">流量加量包：只增加 <b>' . (int)$plan['traffic_gb'] . ' GB</b> 流量，<b>到期时间不变</b>，不延长天数。</div>';
            echo '<label>邮箱（必须是已购买套餐的邮箱）<input type="email" name="email" required placeholder="you@example.com"></label>';
            echo '<label>联系方式（选填，QQ/微信）<input type="text" name="contact" placeholder="方便售后联系"></label>';
            echo '<button class="btn wide" type="submit">提交订单</button></form></div>';
            return;
        }

        if ($scope === 'all') {
            echo '<div class="alert ok">本套餐包含 <b>全部 ' . count($servers) . ' 个地区</b>的节点，开通后只需一个订阅链接即可使用所有节点。</div>';
            echo '<input type="hidden" name="server_id" value="0">';
            echo '<div class="regionpick">';
            foreach ($servers as $s) {
                echo '<span class="region-chip">🌍 ' . e(server_region($s)) . (server_city($s) !== '' ? ' · ' . e(server_city($s)) : '') . '</span>';
            }
            echo '</div>';
        } else {
            echo '<label>选择节点地区</label><div class="regionpick">';
            foreach ($servers as $i => $s) {
                echo '<label class="pick"><input type="radio" name="server_id" value="' . (int)$s['id'] . '"' . ($i === 0 ? ' checked' : '') . ' required>';
                echo '<span><b>🌍 ' . e(server_region($s)) . '</b>' . (server_city($s) !== '' ? '<em>' . e(server_city($s)) . '</em>' : '') . '</span></label>';
            }
            if (!$servers) echo '<div class="alert warn">暂无可售节点</div>';
            echo '</div>';
        }

        echo '<label>邮箱（用于接收订阅，务必填写正确）<input type="email" name="email" required placeholder="you@example.com"></label>';
        echo '<label>联系方式（选填，QQ/微信）<input type="text" name="contact" placeholder="方便售后联系"></label>';
        echo '<button class="btn wide" type="submit">提交订单</button>';
        echo '</form></div>';
    });
}

elseif ($path === '/order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $planId = (int)($_POST['plan_id'] ?? 0);
    $serverId = (int)($_POST['server_id'] ?? 0);
    $email  = trim((string)($_POST['email'] ?? ''));
    $contact = trim((string)($_POST['contact'] ?? ''));
    $st = db()->prepare("SELECT * FROM plans WHERE id=? AND enabled=1");
    $st->execute([$planId]);
    $plan = $st->fetch();
    if (!$plan) { flash('套餐不存在', 'err'); redirect('/'); }
    if (plan_kind($planId) === 'topup') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('邮箱格式不正确', 'err'); redirect('/buy?plan=' . $planId); }
        $chk = topup_check($email);
        if (empty($chk['ok'])) { flash($chk['msg'], 'err'); redirect('/buy?plan=' . $planId); }
        $no = order_no();
        $ins = db()->prepare("INSERT INTO orders (order_no,plan_id,plan_name,days,traffic_gb,amount,email,contact,status,created_at,server_id,region,note)
                              VALUES (?,?,?,?,?,?,?,?,'pending',?,0,'加量包','topup')");
        $ins->execute([$no, (int)$plan['id'], (string)$plan['name'], 0, (int)$plan['traffic_gb'], (float)$plan['price'],
                       $email, $contact, now()]);
        redirect('/pay/' . $no);
    }

    $scope = plan_scope($planId);
    if ($scope === 'all') {
        $srv = null; $serverId = 0; $region = '全地区';
    } else {
        $srv = get_server($serverId);
        if (!$srv || !(int)$srv['enabled']) { flash('请选择有效的节点地区', 'err'); redirect('/buy?plan=' . $planId); }
        $region = server_region($srv);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('邮箱格式不正确', 'err'); redirect('/buy?plan=' . $planId); }

    $no = order_no();
    $ins = db()->prepare("INSERT INTO orders (order_no,plan_id,plan_name,days,traffic_gb,amount,email,contact,status,created_at,server_id,region)
                          VALUES (?,?,?,?,?,?,?,?,'pending',?,?,?)");
    $orderDays = (int)$plan['days'] > 0 ? (int)$plan['days'] : NO_EXPIRE_DAYS;
    $ins->execute([$no, $plan['id'], $plan['name'], $orderDays, $plan['traffic_gb'], $plan['price'],
                   $email, $contact, now(), $serverId, $region]);
    redirect('/pay/' . $no);
}

elseif (preg_match('#^/pay/([A-Za-z0-9]+)/status$#', $path, $m)) {
    $o = get_order($m[1]);
    if (!$o) { http_response_code(404); exit('{"status":"none"}'); }
    $ch = (string)($_GET['ch'] ?? '');
    if ($o['status'] === 'pending' && $ch === 'alipay_qr' && alipay_configured('alipay_qr')) {
        $q = alipay_query($o['order_no'], 'alipay_qr');
        if ($q['ok'] && in_array($q['state'], ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
            mark_paid($o['order_no'], $q['trade'], 'alipay_qr');
        }
    } elseif ($o['status'] === 'pending'
              && in_array($ch, ['wechat_native','wechat_h5','wechat_jsapi'], true)
              && wechat_configured($ch)) {
        $q = wechat_query($o['order_no'], $ch);
        if ($q['ok'] && $q['state'] === 'SUCCESS') mark_paid($o['order_no'], $q['trade'], $ch);
    }
    $o = get_order($m[1]);
    header('Content-Type: application/json');
    echo json_encode(['status' => $o['status'], 'paid' => in_array($o['status'], ['paid', 'provisioned'], true)]);
    exit;
}

elseif (preg_match('#^/pay/([A-Za-z0-9]+)$#', $path, $m)) {
    $o = get_order($m[1]);
    if (!$o) { http_response_code(404); exit('订单不存在'); }
    if ($o['status'] === 'provisioned') redirect('/order/' . $o['order_no']);

    // 微信 JSAPI：授权回调 → 下单 → 拉起支付
    if ($o['status'] === 'pending' && (string)($_GET['ch'] ?? '') === 'wechat_jsapi') {
        $appid  = trim(setting('wechat_jsapi_appid'));
        $secret = secret_decrypt(setting('wechat_jsapi_secret'));
        $code   = (string)($_GET['code'] ?? '');
        if ($code === '') {
            redirect(wechat_oauth_url($appid, site_base() . '/pay/' . $o['order_no'] . '?ch=wechat_jsapi'));
        }
        $ou = wechat_openid($appid, $secret, $code);
        if (!$ou['ok']) { flash('微信授权失败：' . $ou['msg'], 'err'); redirect('/pay/' . $o['order_no']); }
        $pre = wechat_jsapi_order($o, (string)$ou['openid']);
        if (!$pre['ok']) { flash('微信下单失败：' . $pre['msg'], 'err'); redirect('/pay/' . $o['order_no']); }
        $wp = wechat_jsapi_params((string)$pre['prepay_id']);
        page('微信支付', function () use ($o, $wp) {
            echo '<div class="panel narrow center"><h2>微信支付</h2>';
            echo '<p class="muted">订单 ' . e($o['order_no']) . ' · ¥' . e(money((float)$o['amount'])) . '</p>';
            echo '<p class="muted small" id="tip">正在拉起微信支付…</p>';
            echo '<script>var P=' . json_encode($wp, JSON_UNESCAPED_SLASHES) . ';';
            echo 'function go(){if(typeof WeixinJSBridge=="undefined"){document.getElementById("tip").innerText="请在微信中打开本页面";return;}'
               . 'WeixinJSBridge.invoke("getBrandWCPayRequest",P,function(r){if(r.err_msg=="get_brand_wcpay_request:ok"){location.href="/order/' . e($o['order_no']) . '";}else if(r.err_msg=="get_brand_wcpay_request:cancel"){document.getElementById("tip").innerText="已取消支付";}else{document.getElementById("tip").innerText="支付失败："+r.err_msg;}});}';
            echo 'document.addEventListener("WeixinJSBridgeReady",go,false);setTimeout(function(){if(typeof WeixinJSBridge!="undefined")go();},800);';
            echo 'setInterval(function(){fetch("/pay/' . e($o['order_no']) . '/status?ch=wechat_jsapi").then(function(r){return r.json();}).then(function(d){if(d.paid)location.href="/order/' . e($o['order_no']) . '";});},3000);</script>';
            echo '</div>';
        });
        exit;
    }

    $avail  = pay_available();
    $chosen = '';
    $qr     = '';
    $err    = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $o['status'] === 'pending') {
        csrf_check();
        $chosen = (string)($_POST['ch'] ?? '');
        if (in_array($chosen, pay_coming_soon(), true)) { flash('该支付方式开发中，请选择其他方式', 'err'); redirect('/pay/' . $o['order_no']); }
        if (!isset($avail[$chosen])) { flash('该支付方式暂不可用', 'err'); redirect('/pay/' . $o['order_no']); }
        try {

        if ($chosen === 'epay') redirect(epay_pay_url($o, ''));
        // 兼容旧表单/书签里的两个易支付通道
        if ($chosen === 'epay_alipay') redirect(epay_pay_url($o, 'alipay'));
        if ($chosen === 'epay_wxpay')  redirect(epay_pay_url($o, 'wxpay'));
        if ($chosen === 'alipay_page') redirect(alipay_page_url($o));
        if ($chosen === 'alipay_wap')  redirect(alipay_wap_url($o));
        if ($chosen === 'alipay_qr') {
            $r = alipay_precreate($o);
            $qr = $r['ok'] ? (string)$r['qr'] : '';
            $err = $r['ok'] ? '' : ('支付宝下单失败：' . ($r['msg'] ?? ''));
        }
        if ($chosen === 'wechat_native') {
            $r = wechat_native($o);
            $qr = $r['ok'] ? (string)$r['qr'] : '';
            $err = $r['ok'] ? '' : ('微信下单失败：' . ($r['msg'] ?? ''));
        }
        if ($chosen === 'wechat_h5') {
            $r = wechat_h5($o, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
            if ($r['ok']) redirect((string)$r['url']);
            $err = '微信 H5 下单失败：' . ($r['msg'] ?? '');
        }
        if ($chosen === 'wechat_jsapi') {
            $appid = trim(setting('wechat_jsapi_appid'));
            $redir = site_base() . '/pay/' . $o['order_no'] . '?ch=wechat_jsapi';
            redirect(wechat_oauth_url($appid, $redir));
        }

        } catch (Throwable $e) {
            $err = '下单失败：' . $e->getMessage();
        }
    }

    page('支付订单', function () use ($o, $avail, $chosen, $qr, $err) {
        echo '<div class="panel narrow"><h2>订单支付</h2>';
        echo '<div class="kv"><span>订单号</span><b>' . e($o['order_no']) . '</b></div>';
        echo '<div class="kv"><span>套餐</span><b>' . e($o['plan_name']) . '</b></div>';
        echo '<div class="kv"><span>地区</span><b>🌍 ' . e($o['region']) . '</b></div>';
        echo '<div class="kv"><span>金额</span><b class="price-sm">¥' . e(money((float)$o['amount'])) . '</b></div>';
        echo '<div class="kv"><span>邮箱</span><b>' . e($o['email']) . '</b></div>';

        if ($qr !== '') {
            $label = $chosen === 'wechat_native' ? '微信' : '支付宝';
            echo '<div class="qrpay"><div id="payqr"></div></div>';
            echo '<div class="copybox"><input id="qrtext" readonly value="' . e($qr) . '"><button class="btn sm" type="button" onclick="copyQr(this)">复制</button></div>';
            echo '<p class="muted center">请用' . e($label) . '扫描二维码支付 ¥' . e(money((float)$o['amount'])) . '，支付成功后自动开通。</p>';
            echo '<p class="muted center small">页面会自动检测支付结果，无需手动刷新。</p>';
            echo '<script src="/assets/qrcode.min.js"></script>';
            echo '<script>(function(){var el=document.getElementById("payqr");if(el&&window.QRCode){new QRCode(el,{text:document.getElementById("qrtext").value,width:220,height:220,correctLevel:QRCode.CorrectLevel.M});}'
               . 'setInterval(function(){fetch("/pay/' . e($o['order_no']) . '/status?ch=' . e($chosen) . '").then(function(r){return r.json();}).then(function(d){if(d.paid){location.href="/order/' . e($o['order_no']) . '";}});},3000);'
               . '})();function copyQr(b){var i=document.getElementById("qrtext");i.select();i.setSelectionRange(0,9999);try{navigator.clipboard.writeText(i.value);}catch(e){document.execCommand("copy");}b.innerText="已复制";setTimeout(function(){b.innerText="复制";},1500);}</script>';
        } elseif ($err !== '') {
            echo '<div class="alert err">' . e($err) . '</div>';
            echo '<a class="btn wide" href="/pay/' . e($o['order_no']) . '">重新选择支付方式</a>';
        } elseif ($o['status'] !== 'pending') {
            $stText = $o['status'] === 'expired' ? '订单已失效（超过 24 小时未付款），请重新下单' : ('订单状态：' . $o['status']);
            echo '<div class="alert ' . ($o['status'] === 'expired' ? 'err' : 'warn') . '">' . e($stText) . '，请到 <a href="/order/' . e($o['order_no']) . '">订单详情</a> 查看。</div>';
        } elseif (!$avail) {
            if (test_mode() && is_admin()) {
                echo '<div class="paylabel">选择支付方式</div>';
                mock_pay_box($o['order_no']);
            } else {
                echo '<div class="alert warn">支付通道维护中，请稍后再试或联系客服。</div>';
            }
        } else {
            echo '<div class="paylabel">选择支付方式</div>';
            // 只显示真正可用的通道，未开通/开发中的一律不渲染
            foreach (pay_categories() as $cat) {
                $rows = [];
                foreach ($cat['methods'] as $id => $c) {
                    if (isset($avail[$id])) $rows[$id] = $c;
                }
                if (!$rows) continue;
                echo '<div class="paygroup"><div class="paygrouptitle">' . e($cat['name']) . '</div>';
                foreach ($rows as $id => $c) {
                    echo '<form method="post" style="margin:6px 0">' . csrf_field();
                    echo '<input type="hidden" name="ch" value="' . e($id) . '">';
                    echo '<button class="btn wide" type="submit">' . e($c['name']) . '<span class="muted small" style="margin-left:8px">' . e($c['note']) . '</span></button>';
                    echo '</form>';
                }
                echo '</div>';
            }
            if (test_mode() && is_admin()) mock_pay_box($o['order_no']);
            echo '<p class="muted center small">支付完成后自动开通，无需手动刷新。</p>';
        }
        echo '</div>';
    });
}

elseif ($path === '/mock-pay' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!is_admin()) { http_response_code(403); exit('无权限'); }
    $o = get_order((string)($_POST['order_no'] ?? ''));
    if (!$o) { http_response_code(404); exit('订单不存在'); }
    mark_paid($o['order_no'], 'MOCK' . time(), 'mock');
    redirect('/order/' . $o['order_no']);
}

elseif ($path === '/notify/alipay') {
    header('Content-Type: text/plain; charset=utf-8');
    if (alipay_notify()) { echo 'success'; } else { http_response_code(400); echo 'fail'; }
    exit;
}

elseif ($path === '/notify/wechat') {
    $body = (string)file_get_contents('php://input');
    $d = wechat_notify($body);
    if ($d === null) wechat_ack(false, '验签失败');
    $no = (string)($d['out_trade_no'] ?? '');
    $o = get_order($no);
    if (!$o) wechat_ack(false, '订单不存在');
    if ((string)($d['trade_state'] ?? '') !== 'SUCCESS') wechat_ack(true);
    $paidFen = (int)($d['amount']['total'] ?? 0);
    if ($paidFen + 1 < (int)round((float)$o['amount'] * 100)) wechat_ack(false, '金额不符');
    mark_paid($no, (string)($d['transaction_id'] ?? ''), (string)($d['_channel'] ?? 'wechat'));
    wechat_ack(true);
}

elseif ($path === '/notify') {
    $p = array_merge($_GET, $_POST);
    if (!epay_enabled() || !epay_verify($p)) { http_response_code(400); exit('sign error'); }
    $o = get_order((string)($p['out_trade_no'] ?? ''));
    if (!$o) exit('no order');
    if (in_array((string)($p['trade_status'] ?? ''), ['TRADE_SUCCESS', 'SUCCESS'], true)) {
        if ((float)($p['money'] ?? 0) + 0.001 < (float)$o['amount']) { slog('warn', 'amount mismatch ' . $o['order_no']); exit('amount'); }
        mark_paid($o['order_no'], (string)($p['trade_no'] ?? ''), (string)($p['type'] ?? ''));
    }
    exit('success');
}

elseif (preg_match('#^/sub/([a-f0-9]{16,64})$#', $path, $m)) {
    $sub = get_sub_by_token($m[1]);
    if ($sub) {
        $rows = sub_accounts((int)$sub['id']);
        $accs = array_map(fn($a) => ['sub_url' => $a['sub_url'], 'region' => $a['region'], 'server_id' => $a['server_id']], $rows);
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $r = merged_subscription($accs, $ua);
        $ct = $r['type'] === 'yaml' ? 'text/yaml' : 'text/plain';
        header('Content-Type: ' . $ct . '; charset=utf-8');
        header('Profile-Update-Interval: 12');
        header('Cache-Control: no-store');
        $totalBytes = (int)round((float)$sub['quota_gb'] * 1073741824);
        $usedBytes  = (int)round((float)$sub['used_gb'] * 1073741824);
        $expireTs   = (int)(strtotime((string)$sub['expire_at']) ?: 0);
        header('Subscription-Userinfo: upload=0; download=' . $usedBytes . '; total=' . $totalBytes . '; expire=' . $expireTs);
        echo $r['body'];
        exit;
    }
    $o = get_order_by_token($m[1]);
    if (!$o) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('subscription not found'); }
    $accs = order_accounts((int)$o['id']);
    if (!$accs) { http_response_code(404); exit('no nodes'); }
    $r = merged_subscription($accs, $_SERVER['HTTP_USER_AGENT'] ?? '');
    $ct = $r['type'] === 'yaml' ? 'text/yaml' : 'text/plain';
    header('Content-Type: ' . $ct . '; charset=utf-8');
    header('Profile-Update-Interval: 12');
    header('Cache-Control: no-store');
    echo $r['body'];
    exit;
}

elseif (preg_match('#^/s/([a-f0-9]{16,64})$#', $path, $m)) {
    $token = $m[1];
    $sub = get_sub_by_token($token);
    $order = $sub ? null : get_order_by_token($token);

    if (!$sub && !$order) {
        http_response_code(404);
        page_head('订阅不存在');
        nav();
        echo '<main class="wrap"><div class="panel narrow center" style="padding:48px 24px">';
        echo '<h2>订阅不存在</h2><p class="muted">链接可能不完整，或订阅已被删除。</p>';
        echo '<p style="margin-top:18px"><a class="btn" href="/query">用邮箱查询订阅</a></p></div></main>';
        page_foot();
        exit;
    }

    // 页面打开时若数据超过 60 秒，顺手同步一次（enforce=false，不会停号）
    if ($sub) {
        $ls = (string)($sub['last_sync'] ?? '');
        if ($ls === '' || strtotime($ls) < time() - 60) {
            try { pool_sync((int)$sub['id'], false); $sub = get_sub((int)$sub['id']) ?: $sub; } catch (Throwable $e) {}
        }
    } elseif ($order) {
        $ls = (string)($order['last_sync'] ?? '');
        if ($ls === '' || strtotime($ls) < time() - 60) {
            try { sync_order_usage($order); $order = get_order_by_token($token) ?: $order; } catch (Throwable $e) {}
        }
    }

    if ($sub) {
        $email    = (string)$sub['email'];
        $quota    = (float)$sub['quota_gb'];
        $used     = (float)$sub['used_gb'];
        $expireTs = strtotime((string)$sub['expire_at']) ?: 0;
        $status   = (string)$sub['status'];
        $planName = '全地区共享套餐';
        $accs     = sub_accounts((int)$sub['id']);
    } else {
        $email    = (string)$order['email'];
        $quota    = (float)$order['traffic_gb'];
        $used     = (float)$order['used_gb'];
        $expireTs = trim((string)($order['expire_at'] ?? '')) !== ''
            ? (strtotime((string)$order['expire_at']) ?: 0)
            : ((strtotime((string)$order['paid_at']) ?: time()) + (int)$order['days'] * 86400);
        $status   = (int)($order['panel_enable'] ?? 1) ? 'active' : 'disabled';
        $planName = (string)$order['plan_name'];
        $accs     = order_accounts((int)$order['id']);
    }

    $left     = max(0, $quota - $used);
    $pct      = $quota > 0 ? min(100, $used / $quota * 100) : 0;
    $daysLeft = $expireTs > 0 ? (int)floor(($expireTs - time()) / 86400) : -1;
    $barCls   = $pct >= 95 ? 'bad' : ($pct >= 80 ? 'warn' : '');
    $stMap    = ['active' => ['正常', 'ok'], 'exhausted' => ['流量已用尽', 'bad'], 'expired' => ['已到期', 'bad']];
    if ($daysLeft < 0 && $expireTs > 0) $stMap['active'] = ['已到期', 'bad'];
    [$stTxt, $stCls] = $stMap[$status] ?? [$status, ''];

    $base = rtrim(cfg('site_url'), '/');
    if ($base === '') {
        $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    $rawUrl = $base . '/sub/' . $token;

    $regions = [];
    foreach ($accs as $a) { if (!empty($a['region'])) $regions[$a['region']] = true; }
    $nodeCount = count($accs);

    // 管理员从后台订阅池/订单进来看到的是「该用户的订阅」，用户自己打开仍是「我的订阅」
    $subHead = is_admin() ? '该用户的订阅' : '我的订阅';
    page_head($subHead);
    nav();
    echo '<main class="wrap">';
    alerts();

    echo '<div class="panel subpanel">';
    echo '<div class="subhead"><div><div class="muted small">' . e($subHead) . '</div>';
    echo '<h1 class="subtitle">' . e($planName) . '</h1>';
    echo '<div class="muted small mono">' . e($email) . '</div>';
    echo '<div class="muted small">最近使用：' . e(ago_text((string)($sub['last_seen'] ?? ($order['last_seen'] ?? '')))) . '</div></div>';
    echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end">';
    echo '<span class="pill ' . $stCls . ' subpill">' . e($stTxt) . '</span>';
    if (is_admin()) {
        $eh = $sub ? '/admin/subs/' . (int)$sub['id'] . '/edit' : '/admin/orders/' . e((string)$order['order_no']) . '/edit';
        echo '<a class="btn xs ghost" href="' . $eh . '">编辑</a>';
    }
    echo '</div></div>';

    echo '<div class="substats">';
    echo '<div><span>已用流量</span><b>' . e(number_format($used, 2)) . '<em>GB</em></b></div>';
    echo '<div><span>剩余流量</span><b>' . e(number_format($left, 2)) . '<em>GB</em></b></div>';
    echo '<div><span>到期时间</span><b class="sm">' . e(expire_label($expireTs, (int)$order['days'])) . '</b></div>';
    echo '<div><span>剩余天数</span><b>' . (is_no_expire((int)$order['days']) ? '不限时' : ($daysLeft >= 0 ? $daysLeft . '<em>天</em>' : '—')) . '</b></div>';
    echo '</div>';

    echo '<div class="progress big"><i class="' . $barCls . '" style="width:' . pct_width($pct) . '"></i></div>';
    echo '<div class="muted small" style="margin-top:8px">已用 ' . e(number_format($used, 2)) . ' / ' . e(number_format($quota, 0)) . ' GB（' . pct_text($pct) . '）';
    if ($nodeCount > 0) echo ' · ' . $nodeCount . ' 条节点' . ($regions ? ' · ' . e(implode(' / ', array_keys($regions))) : '');
    echo '</div>';
    echo '</div>';

    echo '<div class="panel qrpanel">';
    echo '<h3>扫码导入</h3>';
    echo '<p class="muted small">手机用客户端扫码，或复制链接手动添加订阅。Clash / v2rayN / 小火箭 / sing-box 都填这一条。</p>';
    echo '<div class="qrbox"><div id="qr"></div></div>';
    echo '<div class="copybox"><input id="suburl" readonly value="' . e($rawUrl) . '"><button class="btn sm" type="button" onclick="copySub(this)">复制</button></div>';
    echo '<p class="muted small">客户端里选择「订阅 / Subscription」→ 粘贴链接 → 更新即可。流量与到期时间会自动显示。</p>';
    echo '</div>';

    echo '<script src="/assets/qrcode.min.js"></script>';
    echo '<script>(function(){var el=document.getElementById("qr");if(el&&window.QRCode){new QRCode(el,{text:document.getElementById("suburl").value,width:196,height:196,correctLevel:QRCode.CorrectLevel.M});}})();'
       . 'function copySub(b){var i=document.getElementById("suburl");i.select();i.setSelectionRange(0,9999);try{navigator.clipboard.writeText(i.value);}catch(e){document.execCommand("copy");}b.innerText="已复制";setTimeout(function(){b.innerText="复制";},1500);}'
       . '</script>';
    page_foot();
    exit;
}

elseif ($path === '/return') {
    $no = (string)($_GET['out_trade_no'] ?? '');
    $o = $no !== '' ? get_order($no) : null;
    if ($o && $o['status'] === 'pending') {
        // 易支付同步返回（带签名的才认）
        if (epay_enabled() && epay_verify($_GET)
            && in_array((string)($_GET['trade_status'] ?? ''), ['TRADE_SUCCESS', 'SUCCESS'], true)) {
            mark_paid($no, (string)($_GET['trade_no'] ?? ''), (string)($_GET['type'] ?? 'epay'));
        } else {
            // 支付宝页面跳回：主动查一次，避免用户看到「待支付」
            foreach (['alipay_page', 'alipay_wap'] as $ac) {
                if (!alipay_configured($ac)) continue;
                $q = alipay_query($no, $ac);
                if ($q['ok'] && in_array($q['state'], ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
                    mark_paid($no, $q['trade'], $ac);
                    break;
                }
            }
        }
    }
    redirect($no !== '' ? '/order/' . $no : '/query');
}

elseif (preg_match('#^/order/([A-Za-z0-9]+)$#', $path, $m)) {
    $o = get_order($m[1]);
    if (!$o) { http_response_code(404); exit('订单不存在'); }
    if ($o['status'] === 'paid') { provision_order((int)$o['id']); $o = get_order($m[1]); }

    page('订单详情', function () use ($o) {
        echo '<div class="panel narrow"><h2>订单详情</h2>';
        $map = ['pending' => ['待支付', 'warn'], 'paid' => ['已支付，开通中', 'warn'],
                'provisioned' => ['已开通', 'ok'], 'failed' => ['开通失败', 'err'],
                'expired' => ['已失效（超过 24 小时未付款）', 'err']];
        [$txt, $cls] = $map[$o['status']] ?? [$o['status'], ''];
        echo '<div class="alert ' . $cls . '">' . e($txt) . '</div>';
        // 支付完成后自动刷新（覆盖从网关跳回时异步通知还没到的空档）
        if (in_array($o['status'], ['pending', 'paid'], true)) {
            echo '<script>(function(){var cur=' . json_encode((string)$o['status']) . ';'
               . 'setInterval(function(){fetch("/pay/' . e((string)$o['order_no']) . '/status")'
               . '.then(function(r){return r.json();})'
               . '.then(function(d){if(d.status&&d.status!==cur){location.reload();}})'
               . '.catch(function(){});},3000);})();</script>';
        }
        echo '<div class="kv"><span>订单号</span><b>' . e($o['order_no']) . '</b></div>';
        echo '<div class="kv"><span>套餐</span><b>' . e($o['plan_name']) . '</b></div>';
        if (!empty($o['region'])) echo '<div class="kv"><span>地区</span><b>🌍 ' . e($o['region']) . '</b></div>';
        echo '<div class="kv"><span>金额</span><b>¥' . e(money((float)$o['amount'])) . '</b></div>';

        if (is_admin() && $o['status'] === 'provisioned') {
            $eh = (int)($o['sub_id'] ?? 0) > 0 ? '/admin/subs/' . (int)$o['sub_id'] . '/edit' : '/admin/orders/' . e((string)$o['order_no']) . '/edit';
            echo '<div class="actions" style="margin:10px 0"><a class="btn ghost" href="' . $eh . '">编辑用户</a></div>';
        }

        if (($o['note'] ?? '') === 'topup' && $o['status'] === 'provisioned') {
            echo '<div class="alert ok">流量加量包已到账：<b>+' . (int)$o['traffic_gb'] . ' GB</b>，原订阅的到期时间不变。</div>';
            $sid = (int)($o['sub_id'] ?? 0);
            if ($sid > 0) {
                $sbT = get_sub($sid);
                if ($sbT) echo '<a class="btn wide" href="/s/' . e((string)$sbT['token']) . '">查看订阅</a>';
            }
            echo '</div>';
            return;
        }

        if ($o['status'] === 'provisioned') {
            $accs     = order_accounts((int)$o['id']);
            $isMerged = ($o['note'] ?? '') === 'merged';
            $isPool   = ($o['note'] ?? '') === 'pool' && !empty($o['sub_id']);
            $sb       = $isPool ? get_sub((int)$o['sub_id']) : null;

            $quota    = $sb ? (float)$sb['quota_gb'] : (float)$o['traffic_gb'];
            $used     = $sb ? (float)$sb['used_gb']  : (float)$o['used_gb'];
            $expireTs = $sb ? (strtotime((string)$sb['expire_at']) ?: 0)
                            : ((strtotime((string)$o['paid_at']) ?: time()) + (int)$o['days'] * 86400);
            $st       = $sb['status'] ?? 'active';
            $stTxt    = ['active' => '正常', 'exhausted' => '流量已用尽', 'expired' => '已到期'][$st] ?? $st;
            $stCls    = $st === 'active' ? 'ok' : 'bad';
            if ($expireTs > 0 && $expireTs < time()) { $stTxt = '已到期'; $stCls = 'bad'; }

            $nodeList = $sb ? sub_accounts((int)$sb['id']) : $accs;
            $nodeCnt  = count($nodeList);
            $regions  = [];
            foreach ($nodeList as $a) { if (!empty($a['region'])) $regions[$a['region']] = true; }

            $left     = max(0, $quota - $used);
            $pct      = $quota > 0 ? min(100, $used / $quota * 100) : 0;
            $bar      = $pct >= 95 ? 'bad' : ($pct >= 80 ? 'warn' : '');
            $daysLeft = $expireTs > 0 ? (int)floor(($expireTs - time()) / 86400) : -1;

            $rawUrl = (string)$o['sub_url'];
            if ($rawUrl === '' && !empty($o['sub_token'])) {
                $b = rtrim(cfg('site_url'), '/');
                if ($b === '') $b = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
                $rawUrl = $b . '/sub/' . $o['sub_token'];
            }

            echo '<div class="subhead" style="margin-bottom:4px"><div class="muted small">订阅状态</div>';
            echo '<span class="pill ' . $stCls . ' subpill">' . e($stTxt) . '</span></div>';

            echo '<div class="substats">';
            echo '<div><span>已用流量</span><b>' . e(number_format($used, 2)) . '<em>GB</em></b></div>';
            echo '<div><span>剩余流量</span><b>' . e(number_format($left, 2)) . '<em>GB</em></b></div>';
            echo '<div><span>到期时间</span><b class="sm">' . e(expire_label($expireTs, (int)$o['days'])) . '</b></div>';
            echo '<div><span>剩余天数</span><b>' . (is_no_expire((int)$o['days']) ? '不限时' : ($daysLeft >= 0 ? $daysLeft . '<em>天</em>' : '—')) . '</b></div>';
            echo '</div>';
            echo '<div class="progress big"><i class="' . $bar . '" style="width:' . pct_width($pct) . '"></i></div>';
            echo '<div class="muted small" style="margin-top:8px">共 ' . e(number_format($quota, 0)) . ' GB，已用 ' . pct_text($pct) . ' · ' . $nodeCnt . ' 条节点'
               . ($regions ? ' · ' . e(implode(' / ', array_keys($regions))) : '') . '</div>';

            echo '<h3 style="margin-top:24px">扫码导入</h3>';
            echo '<div class="qrbox qrcenter"><div id="qr"></div></div>';
            echo '<div class="copybox"><input id="suburl" readonly value="' . e($rawUrl) . '">';
            echo '<button class="btn sm" type="button" onclick="copySub(this)">复制</button></div>';
            echo '<p class="muted small">客户端选「订阅 / Subscription」→ 粘贴链接即可。同邮箱续费会自动叠加，不会新建账号。用量每 5 分钟同步一次。</p>';

            if ($isMerged && $accs) {
                echo '<details class="muted small" style="margin-top:10px"><summary>查看各地区独立链接</summary>';
                foreach ($accs as $a) {
                    echo '<div class="kv"><span>' . e($a['region']) . '</span><b class="mono">' . e($a['sub_url']) . '</b></div>';
                }
                echo '</details>';
            }

            echo "<script src=\"/assets/qrcode.min.js\"></script>";
            echo "<script>(function(){var el=document.getElementById('qr');if(el&&window.QRCode&&document.getElementById('suburl').value){new QRCode(el,{text:document.getElementById('suburl').value,width:196,height:196,correctLevel:QRCode.CorrectLevel.M});}})();"
               . "function copySub(b){var i=document.getElementById('suburl');i.select();i.setSelectionRange(0,9999);try{navigator.clipboard.writeText(i.value);}catch(e){document.execCommand('copy');}b.innerText='已复制';setTimeout(function(){b.innerText='复制';},1500);}</script>";
        } elseif ($o['status'] === 'pending') {
            echo '<a class="btn wide" href="/pay/' . e($o['order_no']) . '">去支付</a>';
        } elseif ($o['status'] === 'failed') {
            echo '<div class="alert err">' . e($o['note']) . '</div><p class="muted">请联系客服，订单号：' . e($o['order_no']) . '</p>';
        }
        echo '</div>';
    });
}

elseif ($path === '/query') {
    expire_stale_orders();
    $email = trim((string)($_GET['email'] ?? ''));
    $rows = [];
    if ($email !== '') {
        $st = db()->prepare("SELECT * FROM orders WHERE email=? AND status<>'expired' ORDER BY id DESC LIMIT 20");
        $st->execute([$email]);
        $rows = $st->fetchAll();
    }
    $paidRows = [];
    $unpaidRows = [];
    foreach ($rows as $r) {
        if (in_array($r['status'], ['paid', 'provisioned'], true)) $paidRows[] = $r;
        else $unpaidRows[] = $r;
    }
    page('我的订阅', function () use ($email, $rows, $paidRows, $unpaidRows) {
        echo '<div class="panel narrow"><h2>查询我的订阅</h2>';
        echo '<form method="get" action="/query"><label>购买时填写的邮箱<input type="email" name="email" required value="' . e($email) . '"></label>';
        echo '<button class="btn" type="submit">查询</button></form>';

        $render = function (array $list) {
            $map = [
                'provisioned' => ['已开通', 'ok'],
                'paid'        => ['已付款', 'ok'],
                'pending'     => ['待付款', 'warn'],
                'failed'      => ['支付失败', 'bad'],
                'refunded'    => ['已退款', 'bad'],
            ];
            foreach ($list as $r) {
                $st = (string)$r['status'];
                [$stTxt, $stCls] = $map[$st] ?? [$st, ''];
                echo '<div class="orderitem"><div><b>' . e($r['plan_name']) . '</b> <span class="pill ' . $stCls . '">' . e($stTxt) . '</span>';
                echo ' · ¥' . e(money((float)$r['amount']));
                if (!empty($r['region'])) echo ' · 🌍 ' . e($r['region']);
                echo '<div class="muted small">' . e($r['created_at']) . ' · ' . e($r['order_no']);
                if ($st === 'pending') {
                    $ts = (strtotime((string)$r['created_at']) ?: time()) + ORDER_TTL;
                    echo ' · <span class="cdwrap">剩余 <span class="cd" data-ts="' . (int)$ts . '">--</span></span>';
                }
                echo '</div></div>';
                echo '<div style="display:flex;gap:6px;flex-wrap:wrap">';
                if ($st === 'pending') echo '<a class="btn sm" href="/pay/' . e($r['order_no']) . '">去支付</a>';
                echo '<a class="btn sm' . ($st === 'pending' ? ' ghost' : '') . '" href="/order/' . e($r['order_no']) . '">查看</a>';
                if (is_admin() && $st === 'provisioned') {
                    $eh = (int)($r['sub_id'] ?? 0) > 0 ? '/admin/subs/' . (int)$r['sub_id'] . '/edit' : '/admin/orders/' . e((string)$r['order_no']) . '/edit';
                    echo '<a class="btn sm ghost" href="' . $eh . '">编辑</a>';
                }
                echo '</div></div>';
            }
        };

        if ($paidRows) {
            echo '<h3 class="qvtitle">已付款</h3>';
            $render($paidRows);
        }
        if ($unpaidRows) {
            echo '<h3 class="qvtitle">未付款</h3>';
            $render($unpaidRows);
        }
        if ($email !== '' && !$rows) echo '<p class="muted">没有找到该邮箱的订单。</p>';
        echo '</div>';
    });
}

/* ================= 后台 ================= */
elseif ($path === '/admin/login') {
    if (!has_admin()) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            $u = trim((string)($_POST['username'] ?? ''));
            $p = (string)($_POST['password'] ?? '');
            if (strlen($p) < 8) { flash('密码至少 8 位', 'err'); redirect('/admin/login'); }
            set_setting('admin_user', $u);
            set_setting('admin_hash', password_hash($p, PASSWORD_DEFAULT));
            set_setting('admin_epoch', (string)time());
            $_SESSION['admin'] = $u;
            $_SESSION['admin_epoch'] = (string)setting('admin_epoch');
            flash('管理员创建成功', 'ok');
            redirect('/admin/servers');
        }
        page_head('初始化'); echo '<main class="wrap"><div class="panel narrow"><h2>初始化管理员</h2>';
        alerts();
        echo '<form method="post">' . csrf_field();
        echo '<label>管理员账号<input type="text" name="username" required value="admin"></label>';
        echo '<label>管理员密码（至少 8 位）<input type="password" name="password" required></label>';
        echo '<button class="btn wide">创建并进入</button></form>';
        echo '<p class="muted small center">这是管理员初始化页面。普通用户无需注册，购买后用邮箱查询订阅。</p>';
        echo '</div></main>'; page_foot(); exit;
    }
    $ip = login_ip();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (login_fails($ip) >= 5) { flash('尝试次数过多，请 10 分钟后再试', 'err'); redirect('/admin/login'); }
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        if ($u === setting('admin_user') && password_verify($p, setting('admin_hash'))) {
            login_record($ip, true);
            db()->prepare("DELETE FROM login_attempts WHERE ip=?")->execute([$ip]);
            $_SESSION['admin'] = $u;
            $_SESSION['admin_epoch'] = (string)setting('admin_epoch');
            redirect('/admin');
        }
        login_record($ip, false);
        flash('账号或密码错误' . (login_fails($ip) >= 3 ? '（再错 ' . (5 - login_fails($ip)) . ' 次将锁定 10 分钟）' : ''), 'err');
    }
    page_head('管理登录'); echo '<main class="wrap"><div class="panel narrow"><h2>管理登录</h2>';
    alerts();
    echo '<form method="post">' . csrf_field();
    echo '<label>账号<input type="text" name="username" required></label>';
    echo '<label>密码<input type="password" name="password" required></label>';
    echo '<button class="btn wide">登录</button></form>';
    echo '<p class="muted small center">管理员后台。普通用户无需注册，购买后在 <a href="/query">我的订阅</a> 输入邮箱即可查询。</p>';
    echo '</div></main>'; page_foot(); exit;
}

elseif ($path === '/admin/logout') {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'] ?? '/', $p['domain'] ?? '', (bool)($p['secure'] ?? false), (bool)($p['httponly'] ?? true));
        session_destroy();
    }
    redirect('/admin/login');
}

/* ---------- 概览 / 数据大屏 ---------- */
elseif ($path === '/admin') {
    require_admin();
    if (isset($_GET['sync'])) {
        $t0 = microtime(true);
        $r = pool_sync_all();
        $sg = sync_single_orders();
        try { stats_snapshot(); } catch (Throwable $e) {}
        $err = 0; foreach ($r as $x) $err += count($x['errors'] ?? []);
        flash('同步完成：' . count($r) . ' 个共享池、' . count($sg) . ' 个单地区订单，用时 ' . round(microtime(true) - $t0, 1) . ' 秒' . ($err ? "，{$err} 个错误" : ''));
        redirect('/admin');
    }

    $subs = db()->query("SELECT * FROM subs ORDER BY used_gb DESC")->fetchAll();
    $singles = db()->query("SELECT * FROM orders WHERE status='provisioned' AND note='single' ORDER BY id DESC LIMIT 300")->fetchAll();

    $rows = [];
    $nowTs = time();
    foreach ($subs as $sb) {
        $exp = strtotime((string)$sb['expire_at']) ?: 0;
        $n = (int)db()->query("SELECT COUNT(*) FROM sub_accounts WHERE sub_id=" . (int)$sb['id'])->fetchColumn();
        $rows[] = [
            'kind' => 'pool', 'email' => (string)$sb['email'], 'label' => '全地区',
            'used' => (float)$sb['used_gb'], 'quota' => (float)$sb['quota_gb'],
            'expire' => $exp, 'nodes' => $n, 'status' => (string)$sb['status'],
            'token' => (string)$sb['token'], 'seen' => (string)($sb['last_seen'] ?? ''),
            'edit' => '/admin/subs/' . (int)$sb['id'] . '/edit',
        ];
    }
    foreach ($singles as $o) {
        $base = strtotime((string)($o['paid_at'] ?: $o['created_at'])) ?: 0;
        $exp = trim((string)($o['expire_at'] ?? '')) !== '' ? (strtotime((string)$o['expire_at']) ?: 0)
                                                           : ($base + ((int)$o['days']) * 86400);
        $rows[] = [
            'kind' => 'single', 'email' => (string)$o['email'], 'label' => (string)$o['region'],
            'used' => (float)$o['used_gb'], 'quota' => (float)$o['traffic_gb'],
            'expire' => $exp, 'nodes' => 1,
            'status' => $exp < $nowTs ? 'expired' : ((float)$o['traffic_gb'] > 0 && (float)$o['used_gb'] >= (float)$o['traffic_gb'] ? 'exhausted' : 'active'),
            'token' => (string)($o['sub_token'] ?? ''), 'seen' => (string)($o['last_seen'] ?? ''),
            'edit' => '/admin/orders/' . (string)$o['order_no'] . '/edit',
        ];
    }
    usort($rows, fn($a, $b) => $b['used'] <=> $a['used']);

    $totalQuota = array_sum(array_column($rows, 'quota'));
    $totalUsed  = array_sum(array_column($rows, 'used'));
    $expiring   = count(array_filter($rows, fn($r) => $r['expire'] > $nowTs && $r['expire'] < $nowTs + 7 * 86400));
    $abnormal   = count(array_filter($rows, fn($r) => in_array($r['status'], ['exhausted', 'expired'], true)));
    $usageRate  = $totalQuota > 0 ? $totalUsed / $totalQuota * 100 : 0;

    // 在线（5 分钟内活动过）
    $since5 = date('Y-m-d H:i:s', $nowTs - 300);
    $q5 = db()->quote($since5);
    $online = (int)db()->query("SELECT COUNT(*) FROM sub_accounts WHERE last_seen >= {$q5}")->fetchColumn()
            + (int)db()->query("SELECT COUNT(*) FROM order_accounts WHERE last_seen >= {$q5}")->fetchColumn();

    // 今日 / 累计
    $today = date('Y-m-d');
    $yday  = date('Y-m-d', $nowTs - 86400);
    $snap = []; $snapStart = [];
    foreach (db()->query("SELECT d, used_gb, start_gb FROM stats_daily") as $r) {
        $snap[(string)$r['d']] = (float)$r['used_gb'];
        $snapStart[(string)$r['d']] = (float)($r['start_gb'] ?? 0);
    }
    $todayBase = $snapStart[$today] ?? ($snap[$yday] ?? 0.0);
    $usedToday = max(0.0, $totalUsed - $todayBase);
    $revToday = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status IN ('paid','provisioned') AND paid_at LIKE " . db()->quote($today . '%'))->fetchColumn();
    $revTotal = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status IN ('paid','provisioned')")->fetchColumn();
    $ordToday = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','provisioned') AND paid_at LIKE " . db()->quote($today . '%'))->fetchColumn();

    // 近 14 天流量（按累计差值）
    $chart = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', $nowTs - $i * 86400);
        if ($d === $today) {
            $val = max(0.0, $totalUsed - ($snapStart[$today] ?? 0.0));
        } elseif (isset($snap[$d])) {
            $val = max(0.0, $snap[$d] - ($snapStart[$d] ?? 0.0));
        } else {
            $val = 0.0;
        }
        $chart[] = ['d' => $d, 'v' => $val];
    }
    $peak = max(array_column($chart, 'v'));
    if ($peak <= 0) {
        $chartMax = 1.0;
    } else {
        $pp = pow(10, floor(log10($peak)));
        $nn = $peak / $pp;
        $chartMax = ($nn <= 1 ? 1 : ($nn <= 2 ? 2 : ($nn <= 5 ? 5 : 10))) * $pp;
    }

    // 服务器
    $servers = all_servers();
    $srvStats = [];
    foreach ($servers as $sv) {
        $sid = (int)$sv['id'];
        $accN = (int)db()->query("SELECT COUNT(*) FROM sub_accounts WHERE server_id={$sid}")->fetchColumn()
              + (int)db()->query("SELECT COUNT(*) FROM order_accounts WHERE server_id={$sid}")->fetchColumn();
        $srvStats[] = ['srv' => $sv, 'acc' => $accN];
    }

    expire_stale_orders();
    $recent = db()->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5")->fetchAll();

    page('概览', function () use ($rows, $totalQuota, $totalUsed, $expiring, $abnormal, $usageRate, $nowTs,
            $online, $usedToday, $revToday, $revTotal, $ordToday, $chart, $chartMax, $srvStats, $recent, $today) {
        echo '<style>@media(min-width:900px){.dashpage{max-width:1280px}}</style>';
        echo '<div class="actions" style="justify-content:space-between;align-items:center">';
        echo '<h2 style="margin:0">概览</h2>';
        echo '<div class="actions" style="margin:0"><a class="btn sm" href="/admin?sync=1">立即同步</a></div></div>';
        echo '<p class="muted small">每 5 分钟自动同步用量；更新于 ' . date('Y-m-d H:i:s') . '</p>';


        /* KPI */
        echo '<div class="kpis">';
        $kpis = [
            ['用户总数', count($rows), '人', ''],
            ['在线用户', $online, '人', $online ? 'ok' : ''],
            ['今日流量', number_format($usedToday, 2), 'GB', ''],
            ['累计流量', number_format($totalUsed, 2), 'GB', ''],
            ['今日收入', '¥' . number_format($revToday, 2), '', $revToday > 0 ? 'ok' : ''],
            ['累计收入', '¥' . number_format($revTotal, 2), '', ''],
        ];
        foreach ($kpis as $k) {
            echo '<div class="kpi ' . $k[3] . '"><div class="lbl">' . e($k[0]) . '</div><div class="big">' . e((string)$k[1]) . '<em>' . e($k[2]) . '</em></div></div>';
        }
        echo '</div>';

        /* 流量池 */
        echo '<div class="panel dashpanel"><div class="paneltop"><h3>流量池</h3><span class="muted small">' . pct_text($usageRate) . ' 已用</span></div>';
        echo '<div class="progress big"><i class="' . ($usageRate >= 90 ? 'bad' : ($usageRate >= 75 ? 'warn' : '')) . '" style="width:' . pct_width($usageRate) . '"></i></div>';
        echo '<div class="poolrow"><div><span>总配额</span><b>' . number_format($totalQuota, 0) . ' GB</b></div>';
        echo '<div><span>已用</span><b>' . number_format($totalUsed, 2) . ' GB</b></div>';
        echo '<div><span>剩余</span><b>' . number_format(max(0, $totalQuota - $totalUsed), 0) . ' GB</b></div>';
        echo '<div><span>7 天内到期</span><b class="' . ($expiring ? 'warn' : '') . '">' . $expiring . ' 人</b></div>';
        echo '<div><span>异常/停用</span><b class="' . ($abnormal ? 'bad' : '') . '">' . $abnormal . ' 人</b></div></div></div>';

        /* 趋势 */
        echo '<div class="panel dashpanel"><div class="paneltop"><h3>近 14 天流量</h3><span class="muted small">单位 GB</span></div>';
        echo '<div class="chart">';
        foreach ($chart as $c) {
            $h = $c['v'] > 0 ? max(3, round($c['v'] / $chartMax * 100)) : 0;
            $isToday = $c['d'] === $today;
            echo '<div class="cbar' . ($isToday ? ' today' : '') . '" title="' . e($c['d']) . '：' . number_format($c['v'], 2) . ' GB">';
            if ($c['v'] > 0) {
                $lbl = rtrim(rtrim(number_format($c['v'], 2, '.', ''), '0'), '.');
                echo '<em style="bottom:calc(' . $h . '% + 3px)">' . e($lbl) . '</em>';
            }
            echo '<i style="height:' . $h . '%"></i>';
            echo '<span>' . e(substr($c['d'], 5)) . '</span></div>';
        }
        echo '</div>';
        if ($chartMax <= 0.001) echo '<p class="muted small center" style="margin:6px 0 0">还没有历史数据，同步几天后这里会出现趋势。</p>';
        echo '</div>';

        /* 服务器 + 最近订单 */
        echo '<div class="dash2col">';
        echo '<div class="panel dashpanel"><div class="paneltop"><h3>服务器</h3><a class="muted small" href="/admin/servers">管理</a></div>';
        if (!$srvStats) echo '<p class="muted small">还没有添加服务器。</p>';
        foreach ($srvStats as $x) {
            $sv = $x['srv'];
            $st = $sv['last_status'] === 'ok' ? ['正常', 'ok'] : ($sv['last_status'] === 'fail' ? ['异常', 'bad'] : ['未检测', '']);
            echo '<div class="srvline"><div><b>🌍 ' . e(server_region($sv)) . '</b><div class="muted small">' . e($sv['name']) . '</div></div>';
            echo '<div class="srvmeta"><span class="pill ' . $st[1] . '">' . $st[0] . '</span><span class="muted small">' . $x['acc'] . ' 个账号</span></div></div>';
        }
        echo '</div>';

        echo '<div class="panel dashpanel"><div class="paneltop"><h3>最近订单</h3><a class="muted small" href="/admin/orders">全部</a></div>';
        if (!$recent) echo '<p class="muted small">暂无订单。</p>';
        foreach ($recent as $o) {
            echo '<div class="ordline"><div><b>' . e($o['email']) . '</b><div class="muted small mono">' . e($o['order_no']) . '</div></div>';
            $eh = (int)($o['sub_id'] ?? 0) > 0 ? '/admin/subs/' . (int)$o['sub_id'] . '/edit' : '/admin/orders/' . e((string)$o['order_no']) . '/edit';
            echo '<div class="srvmeta"><b>¥' . e(money((float)$o['amount'])) . '</b><span class="muted small">' . e(ago_text((string)$o['created_at'])) . '</span><a class="btn xs ghost" href="' . $eh . '">编辑</a></div></div>';
        }
        echo '</div></div>';

        /* 用户列表 */
        echo '<div class="panel dashpanel" style="padding:18px"><div class="paneltop"><h3>用户</h3><span class="muted small">按已用流量排序</span></div>';
        echo '<table class="dashtable"><tr><th>用户</th><th>类型</th><th style="min-width:200px">已用 / 总量</th><th>到期</th><th>剩余</th><th>最近使用</th><th>状态</th><th>操作</th></tr>';
        foreach ($rows as $r) {
            $pct = $r['quota'] > 0 ? min(100, $r['used'] / $r['quota'] * 100) : 0;
            $barCls = $pct >= 95 ? 'bad' : ($pct >= 75 ? 'warn' : 'ok');
            $days = (int)floor(($r['expire'] - $nowTs) / 86400);
            $expTxt = expire_label($r['expire']);
            $leftTxt = $days >= 0 ? ($days . ' 天') : '已过期';
            $leftCls = $days < 0 ? 'bad' : ($days <= 7 ? 'warn' : '');
            $stMap = ['active' => ['正常', 'ok'], 'exhausted' => ['流量用尽', 'bad'], 'expired' => ['已到期', 'bad']];
            [$stTxt, $stCls] = $stMap[$r['status']] ?? [$r['status'], ''];
            echo '<tr><td><b>' . e($r['email']) . '</b></td>';
            echo '<td><span class="pill">' . e($r['label']) . '</span></td>';
            echo '<td><div class="mrow"><span>' . e(number_format($r['used'], 2)) . ' / ' . e(number_format($r['quota'], 0)) . ' GB</span><span class="muted small">' . pct_text($pct) . '</span></div>';
            echo '<div class="progress"><i class="' . $barCls . '" style="width:' . pct_width($pct) . '"></i></div></td>';
            echo '<td class="small">' . e($expTxt) . '</td>';
            echo '<td class="small ' . $leftCls . '">' . e($leftTxt) . '</td>';
            echo '<td class="small">' . e(ago_text($r['seen'])) . '</td>';
            echo '<td><span class="pill ' . $stCls . '">' . e($stTxt) . '</span></td>';
            echo '<td><div style="display:flex;gap:6px;flex-wrap:wrap">';
            if ($r['token'] !== '') echo '<a class="btn xs ghost" target="_blank" href="/s/' . e($r['token']) . '">查看</a>';
            echo '<a class="btn xs" href="' . e($r['edit']) . '">编辑</a></div></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="8" class="center muted">还没有订阅数据，卖出第一单后这里会自动出现</td></tr>';
        echo '</table></div>';
    });
}

elseif ($path === '/admin/dashboard') {
    require_admin();
    redirect('/admin');
}

/* ---------- 服务器管理 ---------- */
elseif (preg_match('#^/admin/servers/(\d+)/edit$#', $path, $m)) {
    require_admin();
    $srv = get_server((int)$m[1]);
    if (!$srv) { http_response_code(404); exit('服务器不存在'); }
    page('编辑服务器', function () use ($srv) {
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h2 style="margin:0">编辑服务器 #' . (int)$srv['id'] . '</h2><a class="btn ghost sm" href="/admin/servers">返回列表</a></div>';
        server_edit_form($srv, '/admin/servers');
    });
}

elseif ($path === '/admin/servers') {
    require_admin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'add' || $act === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $url = trim((string)($_POST['admin_url'] ?? ''));
            $pw  = (string)($_POST['password'] ?? '');
            $name = trim((string)($_POST['name'] ?? ''));
            $region = trim((string)($_POST['region'] ?? ''));
            $sort = (int)($_POST['sort'] ?? 0);
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            [$b, $p, $u] = HiddifyPanel::parseAdminUrl($url);
            if ($b === '') { flash('管理员地址格式不正确', 'err'); redirect('/admin/servers'); }

            if ($act === 'add') {
                $st = db()->prepare("INSERT INTO servers (name,admin_url,password,region,enabled,sort) VALUES (?,?,?,?,?,?)");
                $st->execute([$name, $url, secret_encrypt($pw), $region, $enabled, $sort]);
                $sid = (int)db()->lastInsertId();
            } else {
                $old = get_server($id);
                if (!$old) { flash('服务器不存在', 'err'); redirect('/admin/servers'); }
                $st = db()->prepare("UPDATE servers SET name=?,admin_url=?,password=?,region=?,enabled=?,sort=? WHERE id=?");
                $st->execute([$name !== '' ? $name : $old['name'], $url, $pw !== '' ? secret_encrypt($pw) : $old['password'], $region, $enabled, $sort, $id]);
                $sid = $id;
            }
            @unlink(DATA_DIR . '/panel_' . $sid . '.cookie');
            // 面板时区：留空自动探测，填了按小时换算
            set_setting('server_tz_h_' . $sid, trim((string)($_POST['tz_offset_h'] ?? '')));
            set_setting('tz_delta_at_' . $sid, '0');
            unset($GLOBALS['__panel_tz'][$sid]);

            // 自动检测地区（未手动填写时）
            if ($region === '') {
                $d = detect_region($url);
                if ($d['ok']) {
                    db()->prepare("UPDATE servers SET region_auto=?, country_code=?, city=? WHERE id=?")
                        ->execute([$d['region'], $d['code'], $d['city'], $sid]);
                    if ($name === '') {
                        $auto = $d['region'] . ($d['city'] !== '' ? '-' . $d['city'] : '');
                        db()->prepare("UPDATE servers SET name=? WHERE id=?")->execute([$auto, $sid]);
                    }
                }
            }
        } elseif ($act === 'del') {
            $id = (int)$_POST['id'];
            db()->prepare("DELETE FROM servers WHERE id=?")->execute([$id]);
            @unlink(DATA_DIR . '/panel_' . $id . '.cookie');
        } elseif ($act === 'toggle') {
            db()->prepare("UPDATE servers SET enabled=1-enabled WHERE id=?")->execute([(int)$_POST['id']]);
        }
        flash('已保存');
        redirect('/admin/servers');
    }

    if (isset($_GET['detect'])) {
        $s = get_server((int)$_GET['detect']);
        if ($s) {
            $d = detect_region($s['admin_url']);
            if ($d['ok']) {
                db()->prepare("UPDATE servers SET region_auto=?, country_code=?, city=? WHERE id=?")
                    ->execute([$d['region'], $d['code'], $d['city'], $s['id']]);
                flash('检测到地区：' . $d['region'] . ($d['city'] !== '' ? ' · ' . $d['city'] : ''));
            } else flash('检测失败：' . ($d['msg'] ?? ''), 'err');
        }
        redirect('/admin/servers');
    }

    if (isset($_GET['test'])) {
        $s = get_server((int)$_GET['test']);
        if ($s) {
            try {
                $r = HiddifyPanel::forServer($s)->test();
                db()->prepare("UPDATE servers SET last_status='ok', last_check=?, last_error='', sub_base=? WHERE id=?")
                    ->execute([now(), $r['sub_base'], $s['id']]);
                flash('连接成功：' . $r['name'] . '（' . $r['mode'] . '）' . "\n" . '订阅路径：' . $r['sub_base']);
            } catch (Throwable $e) {
                db()->prepare("UPDATE servers SET last_status='fail', last_check=?, last_error=? WHERE id=?")
                    ->execute([now(), mb_substr($e->getMessage(), 0, 500), $s['id']]);
                flash('连接失败：' . $e->getMessage(), 'err');
            }
        }
        redirect('/admin/servers');
    }

    $servers = all_servers();
    $sq = trim((string)($_GET['q'] ?? ''));
    $sst = (string)($_GET['st'] ?? '');
    if ($sq !== '' || $sst !== '') {
        $servers = array_values(array_filter($servers, function ($s) use ($sq, $sst) {
            if ($sq !== '') {
                $hay = strtolower(server_region($s) . ' ' . ($s['name'] ?? '') . ' ' . ($s['city'] ?? '') . ' ' . ($s['country_code'] ?? '') . ' ' . ($s['admin_url'] ?? ''));
                if (strpos($hay, strtolower($sq)) === false) return false;
            }
            if ($sst === 'ok')     return ($s['last_status'] ?? '') === 'ok';
            if ($sst === 'fail')   return ($s['last_status'] ?? '') === 'fail';
            if ($sst === 'none')   return ($s['last_status'] ?? '') === '';
            if ($sst === 'off')    return (int)($s['enabled'] ?? 0) === 0;
            return true;
        }));
    }
    if (isset($_GET['edit'])) redirect('/admin/servers/' . (int)$_GET['edit'] . '/edit');
    page('服务器管理', function () use ($servers, $sq, $sst) {
        echo '<h2>服务器管理</h2>';
        echo '<form method="get" action="/admin/servers" class="panel filterbar cols2">';
        echo '<label>关键词<input type="text" name="q" value="' . e($sq) . '" placeholder="地区 / 名称 / 城市 / 域名"></label>';
        echo '<label>状态<select name="st" data-nice><option value="">全部状态</option>';
        foreach (['ok' => '正常', 'fail' => '异常', 'none' => '未检测', 'off' => '已下架'] as $k => $v) {
            echo '<option value="' . $k . '"' . ($sst === $k ? ' selected' : '') . '>' . $v . '</option>';
        }
        echo '</select></label>';
        echo '<div class="actions"><button class="btn">筛选</button><a class="btn ghost" href="/admin/servers">重置</a></div></form>';
        echo '<p class="muted small">共 ' . count($servers) . ' 台服务器。</p>';
        echo '<div class="alert warn small">添加方式：复制 Hiddify 后台完整登录链接（含 UUID）+ 密码，例如 https://域名/后台路径/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx</div>';
        echo '<table><tr><th>ID</th><th>地区</th><th>名称</th><th>地址</th><th>状态</th><th>操作</th></tr>';
        foreach ($servers as $s) {
            $host = parse_url($s['admin_url'], PHP_URL_HOST) ?: '';
            $statusTxt = $s['last_status'] === 'ok' ? '<span class="tag provisioned">正常</span>'
                : ($s['last_status'] === 'fail' ? '<span class="tag failed">异常</span>' : '<span class="tag">未检测</span>');
            echo '<tr><td>' . (int)$s['id'] . '</td>';
            echo '<td><b>🌍 ' . e(server_region($s)) . '</b>' . (server_city($s) !== '' ? '<div class="small muted">' . e(server_city($s)) . ($s['country_code'] !== '' ? ' · ' . e($s['country_code']) : '') . '</div>' : '') . '</td>';
            echo '<td>' . e($s['name']) . '</td><td class="mono">' . e($host) . '</td>';
            echo '<td>' . $statusTxt . ((int)$s['enabled'] ? '' : ' <span class="tag">已下架</span>') . '</td><td>';
            echo '<a class="btn xs ghost" href="/admin/servers?test=' . (int)$s['id'] . '">测试</a> ';
            echo '<a class="btn xs ghost" href="/admin/servers?detect=' . (int)$s['id'] . '">检测地区</a> ';
            echo '<a class="btn xs ghost" href="/admin/servers/' . (int)$s['id'] . '/edit">编辑</a> ';
            echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="' . (int)$s['id'] . '"><button class="btn xs">上/下架</button></form> ';
            echo '<form method="post" class="inline" data-confirm="确定删除这台服务器？">' . csrf_field() . '<input type="hidden" name="act" value="del"><input type="hidden" name="id" value="' . (int)$s['id'] . '"><button class="btn xs danger">删除</button></form>';
            echo '</td></tr>';
        }
        echo '</table>';

        server_edit_form(null);
    });
}

elseif ($path === '/admin/subs') {
    // 旧地址兼容
    redirect('/admin/users');
}

elseif ($path === '/admin/users') {
    require_admin();
    expire_stale_orders();
    if (isset($_GET['sync'])) {
        $r = pool_sync((int)$_GET['sync']);
        flash('同步完成：已用 ' . number_format((float)($r['used'] ?? 0), 2) . ' GB / ' . number_format((float)($r['quota'] ?? 0), 2) . ' GB'
            . (empty($r['errors']) ? '' : '；错误：' . implode(' | ', $r['errors'])));
        redirect('/admin/users');
    }
    if (isset($_GET['syncall'])) {
        $all = pool_sync_all();
        $errs = 0; foreach ($all as $x) $errs += count($x['errors'] ?? []);
        flash('已同步 ' . count($all) . ' 个订阅' . ($errs ? "，{$errs} 个错误" : ''));
        redirect('/admin/users');
    }

    // 按邮箱聚合：全地区订阅 + 单地区订单
    $subs   = db()->query("SELECT * FROM subs ORDER BY id DESC")->fetchAll();
    $orders = db()->query("SELECT * FROM orders WHERE status='provisioned' ORDER BY id DESC")->fetchAll();
    $users  = [];
    $keyOf  = function ($email) { return strtolower(trim((string)$email)); };
    foreach ($subs as $sb) {
        $k = $keyOf($sb['email']);
        if (!isset($users[$k])) $users[$k] = ['email' => $sb['email'], 'pool' => null, 'singles' => []];
        $users[$k]['pool'] = $sb;
    }
    foreach ($orders as $o) {
        if ((string)($o['note'] ?? '') !== 'single') continue;
        $k = $keyOf($o['email']);
        if (!isset($users[$k])) $users[$k] = ['email' => $o['email'], 'pool' => null, 'singles' => []];
        $users[$k]['singles'][] = $o;
    }
    foreach ($users as &$u) {
        $used = 0.0; $quota = 0.0; $exp = ''; $seen = '';
        if ($u['pool']) {
            $used  += (float)$u['pool']['used_gb'];
            $quota += (float)$u['pool']['quota_gb'];
            $exp    = max($exp, (string)$u['pool']['expire_at']);
            $seen   = max($seen, (string)($u['pool']['last_seen'] ?? ''));
        }
        foreach ($u['singles'] as $o) {
            $used  += (float)$o['used_gb'];
            $quota += (float)$o['traffic_gb'];
            $e = trim((string)($o['expire_at'] ?? ''));
            if ($e === '') {
                $base = strtotime((string)($o['paid_at'] ?: $o['created_at'])) ?: time();
                $e = date('Y-m-d H:i:s', $base + ((int)$o['days']) * 86400);
            }
            $exp  = max($exp, $e);
            $seen = max($seen, (string)($o['last_seen'] ?? ''));
        }
        $u['used'] = $used; $u['quota'] = $quota; $u['expire'] = $exp; $u['seen'] = $seen;
    }
    unset($u);
    usort($users, function ($a, $b) { return $b['used'] <=> $a['used']; });

    // 筛选
    $q     = trim((string)($_GET['q'] ?? ''));
    $type  = (string)($_GET['type'] ?? '');
    $state = (string)($_GET['state'] ?? '');
    $nowTs = time();
    $filtered = [];
    foreach ($users as $u) {
        if ($q !== '' && stripos((string)$u['email'], $q) === false) continue;
        $hasPool   = $u['pool'] !== null;
        $hasSingle = !empty($u['singles']);
        if ($type === 'pool' && !$hasPool) continue;
        if ($type === 'single' && !$hasSingle) continue;
        $expTs = strtotime((string)$u['expire']) ?: 0;
        $isExp = $expTs > 0 && $expTs < $nowTs;
        $isExh = $u['quota'] > 0 && $u['used'] >= $u['quota'];
        if ($state === 'active' && ($isExp || $isExh)) continue;
        if ($state === 'expired' && !$isExp) continue;
        if ($state === 'exhausted' && !$isExh) continue;
        $filtered[] = $u;
    }
    $users = $filtered;

    page('用户', function () use ($users, $q, $type, $state) {
        echo '<div class="actions" style="justify-content:space-between;align-items:center">';
        echo '<h2 style="margin:0">用户</h2>';
        echo '<div class="actions" style="margin:0"><a class="btn" href="/admin/subs/new">添加用户</a><a class="btn ghost" href="/admin/users?syncall=1">全部立即同步</a></div></div>';
        echo '<p class="muted small">同一邮箱的全地区订阅和单地区订阅会归到一个用户名下。点「查看」看该用户的全部套餐。</p>';
        echo '<form method="get" action="/admin/users" class="panel filterbar cols3">';
        echo '<label>关键词<input type="text" name="q" value="' . e($q) . '" placeholder="邮箱（支持部分匹配）"></label>';
        echo '<label>类型<select name="type" data-nice><option value="">全部类型</option>';
        echo '<option value="pool"' . ($type === 'pool' ? ' selected' : '') . '>全地区</option>';
        echo '<option value="single"' . ($type === 'single' ? ' selected' : '') . '>单地区</option>';
        echo '</select></label>';
        echo '<label>状态<select name="state" data-nice><option value="">全部状态</option>';
        echo '<option value="active"' . ($state === 'active' ? ' selected' : '') . '>正常</option>';
        echo '<option value="expired"' . ($state === 'expired' ? ' selected' : '') . '>已到期</option>';
        echo '<option value="exhausted"' . ($state === 'exhausted' ? ' selected' : '') . '>流量用尽</option>';
        echo '</select></label>';
        echo '<div class="actions"><button class="btn">筛选</button><a class="btn ghost" href="/admin/users">重置</a></div>';
        echo '</form>';
        echo '<p class="muted small">共 ' . count($users) . ' 位用户。</p>';
        if (!$users) { echo '<div class="panel"><p class="muted">没有符合条件的用户。带筛选条件时点「重置」看全部。</p></div>'; return; }
        echo '<table><tr><th>用户</th><th>类型</th><th>已用 / 总量</th><th>到期</th><th>最近使用</th><th>操作</th></tr>';
        foreach ($users as $u) {
            $pct = $u['quota'] > 0 ? $u['used'] / $u['quota'] * 100 : 0;
            $fresh = $u['expire'] !== '' && strtotime($u['expire']) < time();
            echo '<tr><td><b>' . e($u['email']) . '</b></td>';
            echo '<td>';
            if ($u['pool']) echo '<span class="pill t-all">全地区</span> ';
            if ($u['singles']) echo '<span class="pill t-single">单地区×' . count($u['singles']) . '</span>';
            echo '</td>';
            echo '<td>' . e(number_format($u['used'], 2)) . ' / ' . e(number_format($u['quota'], 0)) . ' GB <span class="muted small">(' . pct_text($pct) . ')</span>';
            echo '<div class="progress"><i class="' . ($pct >= 95 ? 'bad' : ($pct >= 75 ? 'warn' : 'ok')) . '" style="width:' . pct_width($pct) . '"></i></div></td>';
            echo '<td class="small">' . e(expire_label(strtotime($u['expire']))) . ($fresh ? ' <span class="pill bad">已过期</span>' : '') . '</td>';
            echo '<td class="small">' . e(ago_text($u['seen'])) . '</td>';
            echo '<td><a class="btn xs" href="/admin/users/' . rawurlencode($u['email']) . '">查看</a></td></tr>';
        }
        echo '</table>';
    });
}

elseif (preg_match('#^/admin/users/([^/]+)/add-traffic$#', $path, $m) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_admin();
    csrf_check();
    $email = urldecode($m[1]);
    $r = admin_add_traffic($email, (float)($_POST['gb'] ?? 0));
    flash(!empty($r['ok']) ? $r['msg'] : '加量失败：' . (string)($r['msg'] ?? ''), !empty($r['ok']) ? 'ok' : 'err');
    redirect('/admin/users/' . rawurlencode($email));
}

elseif (preg_match('#^/admin/users/([^/]+)$#', $path, $m)) {
    require_admin();
    $email  = urldecode($m[1]);
    $st = db()->prepare("SELECT * FROM subs WHERE email=? ORDER BY id DESC LIMIT 1"); $st->execute([$email]);
    $pool = $st->fetch() ?: null;
    $st = db()->prepare("SELECT * FROM orders WHERE email=? AND note='single' AND status='provisioned' ORDER BY id DESC"); $st->execute([$email]);
    $singles = $st->fetchAll();
    if (!$pool && !$singles) { http_response_code(404); exit('用户不存在'); }

    $poolPlan = '';
    if ($pool) {
        $st = db()->prepare("SELECT plan_name FROM orders WHERE sub_id=? ORDER BY id DESC LIMIT 1"); $st->execute([(int)$pool['id']]);
        $poolPlan = (string)($st->fetchColumn() ?: '');
        $st = db()->prepare("SELECT COUNT(*) FROM sub_accounts WHERE sub_id=?"); $st->execute([(int)$pool['id']]);
        $poolNodes = (int)$st->fetchColumn();
    }

    page('用户 · ' . $email, function () use ($email, $pool, $singles, $poolPlan, $poolNodes) {
        echo '<div class="actions" style="justify-content:space-between;align-items:center">';
        echo '<h2 style="margin:0;overflow-wrap:anywhere">' . e($email) . '</h2>';
        echo '<a class="btn ghost" href="/admin/users">返回</a></div>';
        echo '<div class="actions" style="margin:8px 0 14px;gap:8px;flex-wrap:wrap">';
        echo '<a class="btn sm" href="/admin/subs/new?email=' . rawurlencode($email) . '">给该用户加套餐</a>';
        echo '<form method="post" action="/admin/users/' . rawurlencode($email) . '/add-traffic" class="actions" style="margin:0;gap:6px">' . csrf_field();
        echo '<input type="number" name="gb" min="1" step="1" placeholder="加量 GB" style="max-width:120px;padding:10px 12px" required>';
        echo '<button class="btn sm">加流量</button></form>';
        echo '<span class="muted small">只加流量，到期时间不变</span></div>';

        if ($pool) {
            $pct = (float)$pool['quota_gb'] > 0 ? (float)$pool['used_gb'] / (float)$pool['quota_gb'] * 100 : 0;
            echo '<div class="panel" style="margin:0 0 14px">';
            echo '<div class="paneltop"><h3>全地区共享 <span class="pill t-all">全地区</span></h3><span class="muted small">' . $poolNodes . ' 个节点</span></div>';
            echo '<div class="kv"><span>套餐</span><b>' . e($poolPlan !== '' ? $poolPlan : '全地区') . '</b></div>';
            echo '<div class="kv"><span>流量</span><b>' . e(number_format((float)$pool['used_gb'], 2)) . ' / ' . e(number_format((float)$pool['quota_gb'], 1)) . ' GB（' . pct_text($pct) . '）</b></div>';
            echo '<div class="progress big"><i class="' . ($pct >= 95 ? 'bad' : ($pct >= 75 ? 'warn' : '')) . '" style="width:' . pct_width($pct) . '"></i></div>';
            echo '<div class="kv"><span>到期</span><b>' . e(expire_label(strtotime((string)$pool['expire_at']))) . '</b></div>';
            echo '<div class="kv"><span>状态</span><b>' . e((string)$pool['status']) . '</b></div>';
            echo '<div class="actions"><a class="btn sm" href="/admin/subs/' . (int)$pool['id'] . '/edit">编辑</a>';
            echo '<a class="btn sm ghost" target="_blank" href="/s/' . e($pool['token']) . '">订阅页</a>';
            echo '<a class="btn sm ghost" href="/admin/users?sync=' . (int)$pool['id'] . '">同步</a></div></div>';
        }

        foreach ($singles as $o) {
            $pct = (float)$o['traffic_gb'] > 0 ? (float)$o['used_gb'] / (float)$o['traffic_gb'] * 100 : 0;
            $exp = trim((string)($o['expire_at'] ?? ''));
            if ($exp === '') { $base = strtotime((string)($o['paid_at'] ?: $o['created_at'])) ?: time(); $exp = date('Y-m-d H:i:s', $base + ((int)$o['days']) * 86400); }
            echo '<div class="panel" style="margin:0 0 14px">';
            echo '<div class="paneltop"><h3>单地区 <span class="pill t-single">单地区</span></h3><span class="muted small">' . e((string)($o['region'] ?: '未知地区')) . '</span></div>';
            echo '<div class="kv"><span>套餐</span><b>' . e((string)$o['plan_name']) . '</b></div>';
            echo '<div class="kv"><span>流量</span><b>' . e(number_format((float)$o['used_gb'], 2)) . ' / ' . e(number_format((float)$o['traffic_gb'], 1)) . ' GB（' . pct_text($pct) . '）</b></div>';
            echo '<div class="progress big"><i class="' . ($pct >= 95 ? 'bad' : ($pct >= 75 ? 'warn' : '')) . '" style="width:' . pct_width($pct) . '"></i></div>';
            echo '<div class="kv"><span>到期</span><b>' . e(expire_label(strtotime($exp), (int)$o['days'])) . '</b></div>';
            echo '<div class="kv"><span>订单号</span><b class="small">' . e((string)$o['order_no']) . '</b></div>';
            echo '<div class="actions"><a class="btn sm" href="/admin/orders/' . e((string)$o['order_no']) . '/edit">编辑</a>';
            echo '<a class="btn sm ghost" href="/order/' . e((string)$o['order_no']) . '">订单详情</a></div></div>';
        }
    });
}

elseif ($path === '/admin/subs/new') {
    require_admin();
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $r = admin_create_order($_POST);
        if (empty($r['ok'])) {
            $err = '开通失败：' . (string)($r['msg'] ?? '未知错误');
        } else {
            $extra = empty($r['errors']) ? '' : '；部分节点失败：' . implode(' | ', $r['errors']);
            flash('已开通：' . (string)($r['sub_url'] ?? '') . $extra, empty($r['errors']) ? 'ok' : 'err');
            redirect('/admin/orders');
        }
    }
    $servers = all_servers(true);
    $prefill = trim((string)($_GET['email'] ?? ''));
    page('添加用户', function () use ($servers, $err, $prefill) {
        echo '<div class="panel narrow"><h2>添加用户</h2>';
        echo '<p class="muted small">管理员手动开通，不经过支付。邮箱已有订阅时（全地区）会自动叠加流量和天数。</p>';
        if ($err !== '') echo '<div class="alert err">' . e($err) . '</div>';
        echo '<form method="post">' . csrf_field();
        echo '<label>类型</label>';
        echo '<div class="row2" style="margin-bottom:6px">';
        echo '<label class="row"><input type="radio" name="kind" value="all" checked onchange="document.getElementById(\'srvrow\').style.display=\'none\'"> 全地区共享（每个节点一个账号，共用流量）</label>';
        echo '<label class="row"><input type="radio" name="kind" value="single" onchange="document.getElementById(\'srvrow\').style.display=\'\'"> 单地区</label>';
        echo '</div>';
        echo '<label>邮箱<input type="email" name="email" required placeholder="用户邮箱，用于查询订阅" value="' . e($prefill) . '"></label>';
        echo '<label>套餐名称（选填）<input type="text" name="plan_name" placeholder="如：年付 / VIP / 手动开通"></label>';
        echo '<label class="row" style="margin:6px 0"><input type="checkbox" name="no_expire" value="1" onchange="var w=document.getElementById(\'addDaysWrap\'),d=document.getElementById(\'adddays\');w.style.display=this.checked?\'none\':\'\';w.parentElement.style.gridTemplateColumns=this.checked?\'1fr\':\'\';d.disabled=this.checked;d.required=!this.checked;"> 不限时（纯流量套餐，用完为止）</label>';
        echo '<div class="row2"><label>流量（GB，0=不限）<input type="number" name="gb" min="0" step="1" value="100"></label>';
        echo '<label id="addDaysWrap">天数<input type="number" name="days" id="adddays" min="1" max="3650" step="1" value="30"></label></div>';
        echo '<div id="srvrow" style="display:none"><label>服务器（单地区必选）<select name="server_id" data-nice>';
        foreach ($servers as $sv) {
            echo '<option value="' . (int)$sv['id'] . '">' . e(server_region($sv) . ' · ' . $sv['name']) . '</option>';
        }
        echo '</select></label></div>';
        echo '<div class="actions"><button class="btn">创建并开通</button><a class="btn ghost" href="/admin/orders">返回</a></div>';
        echo '</form></div>';
    });
}

elseif (preg_match('#^/admin/subs/(\d+)/edit$#', $path, $m)) {
    require_admin();
    $sid = (int)$m[1];
    $sub = get_sub($sid);
    if (!$sub) { http_response_code(404); exit('订阅不存在'); }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $r = admin_update_sub($sid, $_POST);
        if (!$r['ok']) {
            $err = (string)$r['msg'];
        } else {
            flash('已保存：' . (int)$r['updated'] . ' 个面板账号已同步'
                . (empty($r['errors']) ? '' : '；失败：' . implode(' | ', $r['errors'])),
                empty($r['errors']) ? 'ok' : 'err');
            redirect('/admin/subs');
        }
        $sub = get_sub($sid) ?: $sub;
    }
    $accs = sub_accounts($sid);
    page('编辑订阅', function () use ($sub, $accs, $err) {
        echo '<div class="panel narrow"><h2>编辑订阅 #' . (int)$sub['id'] . '</h2>';
        if ($err !== '') echo '<div class="alert err">' . e($err) . '</div>';
        echo '<form method="post">' . csrf_field();
        echo '<label>邮箱<input type="email" name="email" value="' . e((string)$sub['email']) . '" placeholder="用户邮箱"></label>';
        echo '<label>备注<input type="text" name="note" value="' . e((string)($sub['note'] ?? '')) . '" placeholder="内部备注（同步为面板备注）"></label>';
        echo '<div class="row2"><label>流量上限（GB，0=不限）<input type="number" step="0.1" min="0" name="limit_gb" value="' . e((string)(float)$sub['quota_gb']) . '"></label>';
        echo '<label>到期时间<input type="date" data-picker name="expire_at" value="' . e(date('Y-m-d', strtotime((string)$sub['expire_at']) ?: time())) . '"></label></div>';
        echo '<label class="row"><input type="checkbox" name="enabled" value="1"' . ($sub['status'] !== 'disabled' ? ' checked' : '') . '> 启用（取消勾选 = 停用，面板账号同时禁用）</label>';
        echo '<div class="actions"><button class="btn">保存并同步</button><a class="btn ghost" href="/admin/subs">返回</a></div>';
        echo '</form>';
        if ($accs) {
            echo '<div class="muted small" style="margin-top:18px">面板账号（' . count($accs) . ' 台）</div>';
            echo '<table><tr><th>地区</th><th>UUID</th><th>已用</th></tr>';
            foreach ($accs as $a) {
                echo '<tr><td>' . e((string)$a['region']) . '</td><td class="mono small">' . e((string)$a['uuid']) . '</td><td>' . e(number_format((float)$a['usage_gb'], 2)) . ' GB</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    });
}

elseif ($path === '/admin/settings') {
    require_admin();
    if (isset($_GET['notify'])) {
        $r = notify_run(false);
        flash('提醒任务：检查 ' . $r['checked'] . ' 个订阅，发送 ' . $r['sent'] . ' 封，跳过 ' . $r['skipped'] . ' 条'
            . ($r['failed'] ? '，失败 ' . $r['failed'] . ' 条' : '')
            . (smtp_configured() ? '' : '（SMTP 未配置）'), $r['failed'] ? 'err' : 'ok');
        redirect('/admin/settings');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['act'] ?? 'save');

        // 恢复默认外观
        if ($act === 'reset_appearance') {
            set_setting('theme_primary', '#2f6b45');
            set_setting('theme_accent', '#7fbf95');
            set_setting('glass_alpha', '55');
            foreach (['bg_pc', 'bg_mobile'] as $bk) {
                $old = (string)setting($bk, '');
                if ($old !== '' && th_valid_bg($old) && is_file(APP_ROOT . '/public' . $old)) @unlink(APP_ROOT . '/public' . $old);
                set_setting($bk, '');
            }
            flash('外观已恢复默认');
            redirect('/admin/settings');
        }

        // 系统更新
        if ($act === 'check_update') {
            $info = upd_check(true);
            if (!empty($info['ok'])) {
                $st  = upd_state();
                $new = upd_has_new($st, $info);
                flash('GitHub 最新版本 v' . $info['version']
                    . ($info['sha'] !== '' ? '（' . substr($info['sha'], 0, 7) . '）' : '')
                    . ($new ? '，发现更新' : '，当前已是最新'), 'ok');
            } else {
                flash((string)($info['msg'] ?? '检查失败'), 'err');
            }
            redirect('/admin/settings');
        }
        if ($act === 'do_update') {
            $r = upd_apply();
            flash($r['ok']
                ? '已更新到 v' . $r['version'] . '，写入 ' . $r['files'] . ' 个文件；原代码已备份为 ' . basename($r['backup'])
                : '更新失败：' . $r['msg'], $r['ok'] ? 'ok' : 'err');
            redirect('/admin/settings');
        }
        foreach (['site_name','site_url','contact','epay_url','epay_pid','epay_key',
                  'smtp_host','smtp_port','smtp_user','smtp_from','smtp_from_name','smtp_secure'] as $k) {
            if (array_key_exists($k, $_POST)) set_setting($k, trim((string)$_POST[$k]));
        }
        // 支付宝：每种方式独立密钥
        foreach (['alipay_page','alipay_wap','alipay_qr'] as $pfx) {
            foreach (['app_id','gateway'] as $f) {
                $k = $pfx . '_' . $f;
                if (array_key_exists($k, $_POST)) set_setting($k, trim((string)$_POST[$k]));
            }
            foreach (['private_key','public_key'] as $f) {
                $k = $pfx . '_' . $f;
                if (!empty($_POST[$k])) set_setting($k, secret_encrypt(trim((string)$_POST[$k])));
            }
        }
        if (array_key_exists('alipay_qr_store_id', $_POST)) set_setting('alipay_qr_store_id', trim((string)$_POST['alipay_qr_store_id']));
        // 微信：每种场景独立配置
        foreach (['wechat_native','wechat_h5','wechat_jsapi'] as $pfx) {
            foreach (['appid','mchid','serial','pubkey_id'] as $f) {
                $k = $pfx . '_' . $f;
                if (array_key_exists($k, $_POST)) set_setting($k, trim((string)$_POST[$k]));
            }
            foreach (['private_key','apiv3_key','pubkey'] as $f) {
                $k = $pfx . '_' . $f;
                if (!empty($_POST[$k])) set_setting($k, secret_encrypt(trim((string)$_POST[$k])));
            }
        }
        if (array_key_exists('wechat_h5_domain', $_POST)) set_setting('wechat_h5_domain', trim((string)$_POST['wechat_h5_domain']));
        if (!empty($_POST['wechat_jsapi_secret'])) set_setting('wechat_jsapi_secret', secret_encrypt(trim((string)$_POST['wechat_jsapi_secret'])));
        // 只有完整表单提交才处理这些"未勾选=关闭"的字段，避免局部 POST 把配置清空
        $fullForm = isset($_POST['settings_form']) && array_key_exists('site_name', $_POST);
        if ($fullForm) {
            set_setting('test_mode', isset($_POST['test_mode']) ? '1' : '0');
            set_setting('notify_enabled', isset($_POST['notify_enabled']) ? '1' : '0');
            // 支付渠道开关
            $chs = array_values(array_intersect((array)($_POST['pay_channels'] ?? []),
                ['epay','epay_alipay','epay_wxpay','alipay_page','alipay_wap','alipay_qr','wechat_native','wechat_h5','wechat_jsapi']));
            if (in_array('epay_alipay', $chs, true) || in_array('epay_wxpay', $chs, true)) $chs[] = 'epay';
            $chs = array_values(array_unique(array_diff($chs, ['epay_alipay', 'epay_wxpay'])));
            set_setting('pay_channels', implode(',', $chs));
            set_setting('pay_channels_set', '1');

            // 首页抽屉自定义菜单
            $nt = (array)($_POST['nav_title'] ?? []);
            $nu = (array)($_POST['nav_url'] ?? []);
            $nl = [];
            foreach ($nt as $i => $t) {
                $t = trim((string)$t);
                $u = trim((string)($nu[$i] ?? ''));
                if ($t === '' || $u === '') continue;
                if (!preg_match('#^(https?://|/)#i', $u)) continue;
                $nl[] = ['title' => mb_substr($t, 0, 20), 'url' => mb_substr($u, 0, 300)];
                if (count($nl) >= 12) break;
            }
            set_setting('nav_links', json_encode($nl, JSON_UNESCAPED_UNICODE));
        }
        // 密钥类：留空表示不修改
        if (!empty($_POST['smtp_pass'])) set_setting('smtp_pass', secret_encrypt(trim((string)$_POST['smtp_pass'])));
        if (isset($_POST['new_admin_pass']) && (string)$_POST['new_admin_pass'] !== '') {
            $p1 = (string)$_POST['new_admin_pass'];
            $p2 = (string)($_POST['new_admin_pass2'] ?? '');
            if (mb_strlen($p1) < 8) {
                flash('新密码至少 8 位', 'err');
                redirect('/admin/settings');
            }
            if ($p2 !== $p1) {
                flash('两次输入的新密码不一致', 'err');
                redirect('/admin/settings');
            }
            set_setting('admin_hash', password_hash($p1, PASSWORD_DEFAULT));
            // 换密码 = 所有已登录会话立即失效
            set_setting('admin_epoch', (string)time());
            $_SESSION = [];
            flash('管理员密码已修改，请用新密码重新登录', 'ok');
            redirect('/admin/login');
        }
        if ($act === 'test_mail') {
            $to = trim((string)($_POST['to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('请填写正确的测试收件邮箱', 'err');
                redirect('/admin/settings');
            }
            $html = notify_mail_html([
                'title' => '这是一封测试邮件',
                'intro' => '如果你收到这封邮件，说明 SMTP 配置正确，到期与流量提醒可以正常发送。',
                'rows' => ['站点名称' => cfg('site_name', '云商城'), '发送时间' => now()],
                'url' => rtrim(cfg('site_url'), '/'), 'foot' => '',
            ]);
            $r = smtp_send($to, cfg('site_name', '云商城') . ' - SMTP 测试', $html);
            flash(($r['ok'] ? '测试邮件已发送：' : '发送失败：') . $r['msg'], $r['ok'] ? 'ok' : 'err');
            redirect('/admin/settings');
        }
        // 外观：主题色 / 玻璃透明度 / 背景图
        if (array_key_exists('theme_primary', $_POST)) {
            $tp = trim((string)$_POST['theme_primary']);
            $ta = trim((string)($_POST['theme_accent'] ?? ''));
            if (!th_valid_hex($tp)) $tp = '#2f6b45';
            if (!th_valid_hex($ta)) $ta = th_lighten($tp, 0.50);
            set_setting('theme_primary', $tp);
            set_setting('theme_accent', $ta);
        }
        if (array_key_exists('glass_alpha', $_POST)) {
            set_setting('glass_alpha', (string)max(20, min(95, (int)$_POST['glass_alpha'])));
        }
        $updir = APP_ROOT . '/public/uploads';
        if (!is_dir($updir)) @mkdir($updir, 0755, true);
        $upErr = '';
        foreach (['bg_pc', 'bg_mobile'] as $bk) {
            if (!empty($_POST['reset_' . $bk])) {
                $old = (string)setting($bk, '');
                if ($old !== '' && th_valid_bg($old) && is_file(APP_ROOT . '/public' . $old)) @unlink(APP_ROOT . '/public' . $old);
                set_setting($bk, '');
            }
            $uf = $_FILES[$bk] ?? null;
            if (!$uf || ($uf['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$uf['tmp_name'])) continue;
            if ((int)$uf['size'] > 8 * 1024 * 1024) { $upErr = '背景图不能超过 8MB'; continue; }
            $info = @getimagesize((string)$uf['tmp_name']);
            $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
            if (!$info || !isset($map[$info[2]])) { $upErr = '背景图只支持 JPG / PNG / WebP'; continue; }
            if ($info[0] < 600 || $info[1] < 400) { $upErr = '背景图尺寸太小，至少 600×400'; continue; }
            $name = $bk . '_' . date('YmdHis') . '.' . $map[$info[2]];
            if (@move_uploaded_file((string)$uf['tmp_name'], $updir . '/' . $name)) {
                $old = (string)setting($bk, '');
                if ($old !== '' && th_valid_bg($old) && is_file(APP_ROOT . '/public' . $old)) @unlink(APP_ROOT . '/public' . $old);
                set_setting($bk, '/uploads/' . $name);
            } else {
                $upErr = '保存背景图失败，请检查 public/uploads 目录权限';
            }
        }

        flash($upErr !== '' ? $upErr : '设置已保存', $upErr !== '' ? 'err' : 'ok');
        redirect('/admin/settings');
    }
    page('系统设置', function () {
        $chs = pay_channels();
        echo '<h2>系统设置</h2>';
        echo '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="settings_form" value="1">';

        echo '<details class="fold" open><summary>站点设置</summary><div class="foldbody">';
        echo '<label>站点名称<input type="text" name="site_name" value="' . e(cfg('site_name', '云商城')) . '"></label>';
        echo '<label>站点地址（支付回调需要）<input type="text" name="site_url" value="' . e(cfg('site_url')) . '" placeholder="https://你的域名"></label>';
        echo '<label>客服联系方式<input type="text" name="contact" value="' . e(cfg('contact')) . '"></label>';
        echo '</div></details>';

        $tp   = theme_params();
        $bgPc = (string)setting('bg_pc', '');
        $bgM  = (string)setting('bg_mobile', '');
        $al   = (int)round($tp['alpha'] * 100);
        echo '<details class="fold"><summary>外观</summary><div class="foldbody">';

        echo '<label>电脑端背景图</label>';
        if ($bgPc !== '') echo '<div class="bgprev"><img src="' . e($bgPc) . '" alt="电脑端背景"></div>';
        echo '<input type="file" name="bg_pc" accept="image/jpeg,image/png,image/webp">';
        echo '<p class="muted small" style="margin:6px 0 0">建议 1920×1080 或更大，JPG/PNG/WebP，不超过 8MB。' . ($bgPc !== '' ? ' 当前已自定义。' : ' 当前使用默认背景。') . '</p>';
        if ($bgPc !== '') echo '<label class="row" style="margin-top:8px"><input type="checkbox" name="reset_bg_pc" value="1"> 恢复默认背景</label>';

        echo '<label style="margin-top:20px">手机端背景图</label>';
        if ($bgM !== '') echo '<div class="bgprev"><img src="' . e($bgM) . '" alt="手机端背景"></div>';
        echo '<input type="file" name="bg_mobile" accept="image/jpeg,image/png,image/webp">';
        echo '<p class="muted small" style="margin:6px 0 0">建议 1080×1920 竖图，手机端单独使用。' . ($bgM !== '' ? ' 当前已自定义。' : ' 当前使用默认背景。') . '</p>';
        if ($bgM !== '') echo '<label class="row" style="margin-top:8px"><input type="checkbox" name="reset_bg_mobile" value="1"> 恢复默认背景</label>';

        echo '<label style="margin-top:22px">玻璃透明度 <output id="alphaOut" style="color:#fff">' . $al . '%</output></label>';
        echo '<input type="range" id="alphaRange" name="glass_alpha" min="20" max="95" step="1" value="' . $al . '" style="width:100%;accent-color:var(--primary)">';
        echo '<p class="muted small" style="margin:6px 0 0">数值越小越透（背景更明显），越大卡片越实。默认 55%。</p>';

        echo '<label style="margin-top:22px">主题色</label>';
        echo '<div class="swatches">';
        foreach (THEME_PRESETS as $pk) {
            [$pname, $pc1, $pc2] = $pk;
            $on = strcasecmp($pc1, $tp['primary']) === 0 ? ' on' : '';
            echo '<button type="button" class="sw' . $on . '" data-p="' . $pc1 . '" data-a="' . $pc2 . '">'
                . '<i style="background:' . $pc1 . '"></i><span>' . e($pname) . '</span></button>';
        }
        echo '</div>';
        echo '<label>自定义颜色</label>';
        echo '<div class="colorrow"><input type="text" id="themeHex" name="theme_primary" value="' . e($tp['primary']) . '" maxlength="7" placeholder="#2f6b45" spellcheck="false">'
            . '<input type="hidden" name="theme_accent" id="themeAccent" value="' . e($tp['accent']) . '">'
            . '<span class="colorchip" id="colorChip" style="background:' . e($tp['primary']) . '"></span></div>';
        echo '<p class="muted small" style="margin:6px 0 0">填 6 位十六进制色值，如 <code>#2f6b45</code>。按钮、边框、高亮会跟着变。</p>';
        echo '<div style="margin-top:20px"><button type="submit" class="btn ghost" name="act" value="reset_appearance" data-confirm="恢复默认主题色、玻璃透明度和两张背景图？已上传的图片会被删除。">恢复默认外观</button></div>';
        echo '</div></details>';

        $navLinks = nav_links();
        echo '<details class="fold"><summary>导航菜单</summary><div class="foldbody">';
        echo '<p class="muted small" style="margin:0 0 14px">首页左上角三条杠里的自定义入口。链接支持 <code>https://</code> 开头的外部地址，或 <code>/</code> 开头的站内路径；最多 12 条。</p>';
        echo '<div id="navrows">';
        if (!$navLinks) $navLinks = [['title' => '', 'url' => '']];
        foreach ($navLinks as $l) {
            echo '<div class="navrow"><input type="text" class="navt" name="nav_title[]" value="' . e($l['title']) . '" placeholder="标题，如 使用教程" maxlength="20">'
               . '<input type="text" class="navu" name="nav_url[]" value="' . e($l['url']) . '" placeholder="https://example.com 或 /query" maxlength="300">'
               . '<button type="button" class="btn ghost sm navdel" aria-label="删除"><span>×</span></button></div>';
        }
        echo '</div>';
        echo '<button type="button" class="btn ghost sm" id="navadd" style="margin-top:4px">+ 添加一项</button>';
        echo '</div></details>';
        echo '<script>(function(){var box=document.getElementById("navrows"),add=document.getElementById("navadd");'
           . 'if(!box||!add)return;'
           . 'function lock(){add.disabled=box.children.length>=12;add.style.opacity=add.disabled?".45":"1";}'
           . 'function row(){var d=document.createElement("div");d.className="navrow";'
           . 'd.innerHTML="<input type=\\"text\\" class=\\"navt\\" name=\\"nav_title[]\\" placeholder=\\"标题，如 使用教程\\" maxlength=\\"20\\"><input type=\\"text\\" class=\\"navu\\" name=\\"nav_url[]\\" placeholder=\\"https://example.com 或 /query\\" maxlength=\\"300\\"><button type=\\"button\\" class=\\"btn ghost sm navdel\\" aria-label=\\"删除\\"><span>×</span></button>";'
           . 'return d;}'
           . 'add.addEventListener("click",function(){if(box.children.length>=12)return;box.appendChild(row());lock();});'
           . 'box.addEventListener("click",function(e){var b=e.target.closest(".navdel");if(!b)return;var r=b.closest(".navrow");'
           . 'if(box.children.length>1){r.remove();}else{r.querySelectorAll("input").forEach(function(i){i.value="";});}lock();});'
           . 'lock();})();</script>';

        echo '<details class="fold"' . (setting('pay_channels') === '' ? ' open' : '') . '><summary>支付设置</summary><div class="foldbody">';
        echo '<label class="row"><input type="checkbox" name="test_mode" value="1" ' . (test_mode() ? 'checked' : '') . '> 测试模式（收银台多出「模拟支付成功」选项，仅管理员可见，不扣款）</label>';
        echo '<p class="muted small" style="margin:10px 0 2px">点开每一行可展开该方式的配置；只显示「已启用 + 配置完整」的方式。</p>';
        $enabledIds = pay_enabled_ids();
        $aliOn = in_array('alipay_page', $enabledIds, true) || in_array('alipay_wap', $enabledIds, true) || in_array('alipay_qr', $enabledIds, true);
        $wxOn  = in_array('wechat_native', $enabledIds, true) || in_array('wechat_h5', $enabledIds, true) || in_array('wechat_jsapi', $enabledIds, true);

        /* ---------- 易支付 ---------- */
        $epayOn = in_array('epay', $enabledIds, true) || in_array('epay_alipay', $enabledIds, true) || in_array('epay_wxpay', $enabledIds, true);
        echo '<details class="fold sub"><summary>易支付 '
           . ($epayOn ? '<span class="pill ok">已启用</span>' : '<span class="pill warn">未配置</span>') . '</summary><div class="foldbody">';
        echo '<p class="muted small" style="margin:0 0 10px">一套配置，启用后收银台同时出现支付宝和微信。</p>';
        echo '<label class="row" style="margin:6px 0"><input type="checkbox" name="pay_channels[]" value="epay"' . ($epayOn ? ' checked' : '') . '> 启用易支付（收银台同时出现支付宝 / 微信）</label>';
        echo '<label>接口地址<input type="text" name="epay_url" value="' . e(cfg('epay_url')) . '" placeholder="https://pay.example.com"></label>';
        echo '<div class="row2"><label>商户 PID<input type="text" name="epay_pid" value="' . e(cfg('epay_pid')) . '"></label>';
        echo '<label>商户密钥<input type="text" name="epay_key" value="' . e(cfg('epay_key')) . '"></label></div>';
        echo '<p class="muted small">回调地址：<code>' . e(site_base()) . '/notify</code></p>';
        echo '</div></details>';

        /* ---------- 支付宝官方 ---------- */
        echo '<details class="fold sub"><summary>支付宝官方 '
           . ($aliOn ? '<span class="pill ok">已启用</span>' : '<span class="pill warn">未配置</span>') . '</summary><div class="foldbody">';
        echo '<p class="muted small" style="margin:0 0 10px">三种方式各自填写，互不影响。</p>';
        $retUrl = site_base() . '/return';
        $aliMethods = [
            'alipay_page' => [
                'name' => '电脑网站支付',
                'need' => ['开放平台应用签约「电脑网站支付」', '应用「授权回调地址」填 ' . $retUrl, '本方式用 alipay.trade.page.pay 下单'],
                'store' => false,
            ],
            'alipay_wap' => [
                'name' => '手机网站支付',
                'need' => ['开放平台应用签约「手机网站支付」', '应用「授权回调地址」填 ' . $retUrl, '本方式用 alipay.trade.wap.pay 下单'],
                'store' => false,
            ],
            'alipay_qr' => [
                'name' => '当面付',
                'need' => ['开放平台应用签约「当面付」', '不需要授权回调地址', '本方式用 alipay.trade.precreate 预下单，接口直接返回二维码'],
                'store' => true,
            ],
        ];
        $soonIds = pay_coming_soon();
        foreach ($aliMethods as $id => $m) {
            $isSoon = in_array($id, $soonIds, true);
            $on = in_array($id, $enabledIds, true);
            $rd = alipay_configured($id);
            echo '<details class="method"><summary>' . e($m['name']) . ' '
               . ($isSoon ? '<span class="pill dev">开发中</span>' : ($rd ? '<span class="pill ok">已配置</span>' : '<span class="pill warn">未配置</span>'))
               . ' <span class="muted small">' . ($isSoon ? '暂未开放' : ('点击' . ($rd ? '设置' : '填写'))) . '</span></summary><div class="methodbody">';
            echo '<label class="row"><input type="checkbox" name="pay_channels[]" value="' . e($id) . '"' . ($on ? ' checked' : '') . ($isSoon ? ' disabled' : '') . '> 启用' . e($m['name']) . ($isSoon ? '<span class="muted small">（开发中，暂不上线）</span>' : '') . '</label>';
            echo '<div class="needbox"><b>这个方式需要：</b><ul>';
            foreach ($m['need'] as $n) echo '<li>' . e($n) . '</li>';
            echo '</ul></div>';
            echo '<label>App ID（该方式对应的开放平台应用）<input type="text" name="' . e($id) . '_app_id" value="' . e(cfg($id . '_app_id')) . '" placeholder="2021000000000000"></label>';
            echo '<label>应用私钥 PKCS8（支持裸 base64）<textarea name="' . e($id) . '_private_key" rows="3" placeholder="' . (cfg($id . '_private_key') !== '' ? '已设置，留空不修改' : 'MIIEvQIBADANBgkq...') . '"></textarea></label>';
            echo '<label>支付宝公钥（开放平台获取，支持裸 base64）<textarea name="' . e($id) . '_public_key" rows="3" placeholder="' . (cfg($id . '_public_key') !== '' ? '已设置，留空不修改' : 'MIIBIjANBgkq...') . '"></textarea></label>';
            echo '<label>网关地址<input type="text" name="' . e($id) . '_gateway" value="' . e(cfg($id . '_gateway', 'https://openapi.alipay.com/gateway.do')) . '"></label>';
            if ($m['store']) {
                echo '<label>门店 ID（选填，连锁门店区分收款用）<input type="text" name="alipay_qr_store_id" value="' . e(cfg('alipay_qr_store_id')) . '"></label>';
            }
            echo '</div></details>';
        }
        echo '</div></details>';

        /* ---------- 微信官方 ---------- */
        echo '<details class="fold sub"><summary>微信支付官方 '
           . '<span class="pill dev">开发中</span></summary><div class="foldbody">';
        echo '<p class="muted small" style="margin:0 0 10px">三种场景各自填写，互不影响。<b>当前未开放，收银台不会显示。</b></p>';
        $host = parse_url(site_base(), PHP_URL_HOST) ?: '';
        $wxMethods = [
            'wechat_native' => [
                'name' => 'Native 扫码',
                'need' => ['商户平台开通「Native 支付」', '填写与商户号绑定的 AppID', '不需要域名或公众号'],
                'h5' => false, 'jsapi' => false,
            ],
            'wechat_h5' => [
                'name' => 'H5 支付',
                'need' => ['商户平台开通「H5 支付」', '商户平台配置 H5 支付域名：' . $host, '只能在手机浏览器里打开'],
                'h5' => true, 'jsapi' => false,
            ],
            'wechat_jsapi' => [
                'name' => 'JSAPI 支付',
                'need' => ['已认证服务号 + 微信支付商户号', '公众号「网页授权域名」填 ' . $host, '商户平台「支付授权目录」填 ' . site_base() . '/pay/', '需要 AppSecret 换取 openid（不是商户 API 密钥）'],
                'h5' => false, 'jsapi' => true,
            ],
        ];
        foreach ($wxMethods as $id => $m) {
            $on = in_array($id, $enabledIds, true);
            $rd = wechat_configured($id);
            echo '<details class="method"><summary>' . e($m['name']) . ' '
               . '<span class="pill dev">开发中</span>'
               . ' <span class="muted small">暂未开放</span></summary><div class="methodbody">';
            echo '<label class="row"><input type="checkbox" name="pay_channels[]" value="' . e($id) . '"' . ($on ? ' checked' : '') . ' disabled> 启用' . e($m['name']) . '<span class="muted small">（开发中，暂不上线）</span></label>';
            echo '<div class="needbox"><b>这个方式需要：</b><ul>';
            foreach ($m['need'] as $n) echo '<li>' . e($n) . '</li>';
            echo '</ul></div>';
            if ($m['jsapi']) {
                echo '<div class="row2"><label>公众号 AppID<input type="text" name="' . e($id) . '_appid" value="' . e(cfg($id . '_appid')) . '"></label>';
                echo '<label>公众号 AppSecret<input type="password" name="wechat_jsapi_secret" placeholder="' . (cfg('wechat_jsapi_secret') !== '' ? '已设置，留空不修改' : 'AppSecret') . '"></label></div>';
            } else {
                echo '<label>AppID<input type="text" name="' . e($id) . '_appid" value="' . e(cfg($id . '_appid')) . '" placeholder="wx0000000000000000"></label>';
            }
            echo '<div class="row2"><label>商户号 MchID<input type="text" name="' . e($id) . '_mchid" value="' . e(cfg($id . '_mchid')) . '" placeholder="1600000000"></label>';
            echo '<label>证书序列号<input type="text" name="' . e($id) . '_serial" value="' . e(cfg($id . '_serial')) . '"></label></div>';
            echo '<label>商户私钥 apiclient_key.pem<textarea name="' . e($id) . '_private_key" rows="3" placeholder="' . (cfg($id . '_private_key') !== '' ? '已设置，留空不修改' : '-----BEGIN PRIVATE KEY-----') . '"></textarea></label>';
            echo '<label>APIv3 密钥（32 位）<input type="password" name="' . e($id) . '_apiv3_key" placeholder="' . (cfg($id . '_apiv3_key') !== '' ? '已设置，留空不修改' : '32 位字符') . '"></label>';
            echo '<div class="row2"><label>微信支付公钥ID（新版商户，选填）<input type="text" name="' . e($id) . '_pubkey_id" value="' . e(cfg($id . '_pubkey_id')) . '" placeholder="PUB_KEY_ID_..."></label>';
            echo '<label>微信支付公钥（选填）<input type="text" name="' . e($id) . '_pubkey" placeholder="' . (cfg($id . '_pubkey') !== '' ? '已设置，留空不修改' : '-----BEGIN PUBLIC KEY-----') . '"></label></div>';
            if ($m['h5']) {
                echo '<label>H5 支付域名（与商户平台填的一致）<input type="text" name="wechat_h5_domain" value="' . e(cfg('wechat_h5_domain')) . '" placeholder="' . e(site_base()) . '"></label>';
            }
            echo '</div></details>';
        }
        echo '</div></details>';
        echo '</div></details>';

        echo '<details class="fold"><summary>邮件通知</summary><div class="foldbody">';
        echo '<label class="row"><input type="checkbox" name="notify_enabled" value="1" ' . (cfg('notify_enabled') === '1' ? 'checked' : '') . '> 启用到期 / 流量提醒邮件</label>';
        echo '<div class="row2"><label>SMTP 服务器<input type="text" name="smtp_host" value="' . e(cfg('smtp_host')) . '" placeholder="smtp.qq.com"></label>';
        echo '<label>端口<input type="number" name="smtp_port" value="' . e(cfg('smtp_port', '465')) . '"></label></div>';
        echo '<label>加密方式<select name="smtp_secure" data-nice>';
        foreach (['ssl' => 'SSL（465，推荐）', 'tls' => 'STARTTLS（587）', 'none' => '不加密（25）'] as $v => $t) {
            echo '<option value="' . $v . '" ' . (cfg('smtp_secure', 'ssl') === $v ? 'selected' : '') . '>' . e($t) . '</option>';
        }
        echo '</select></label>';
        echo '<div class="row2"><label>邮箱账号<input type="text" name="smtp_user" value="' . e(cfg('smtp_user')) . '" placeholder="you@qq.com"></label>';
        echo '<label>授权码<input type="password" name="smtp_pass" placeholder="' . (cfg('smtp_pass') !== '' ? '已设置，留空不修改' : 'QQ 邮箱授权码') . '"></label></div>';
        echo '<div class="row2"><label>发件人地址<input type="text" name="smtp_from" value="' . e(cfg('smtp_from')) . '" placeholder="留空=邮箱账号"></label>';
        echo '<label>发件人名称<input type="text" name="smtp_from_name" value="' . e(cfg('smtp_from_name')) . '"></label></div>';
        echo '<div style="height:1px;background:rgba(255,255,255,.08);margin:16px 0"></div>';
        echo '<label>测试收件邮箱<input type="email" name="to" placeholder="you@example.com"></label>';
        echo '<div class="actions"><button class="btn ghost" type="submit" name="act" value="test_mail">发送测试邮件</button>';
        echo '<a class="btn ghost" href="/admin/settings?notify=1">立即检查并发送提醒</a></div>';
        echo '<p class="muted small">提醒任务：<code>php 站点目录/bin/notify.php</code>，建议每 30 分钟一次。</p>';
        echo '</div></details>';

        echo '<details class="fold"><summary>管理员密码</summary><div class="foldbody">';
        echo '<label>新密码（至少 8 位，留空不修改）<input type="password" name="new_admin_pass" autocomplete="new-password" minlength="8"></label>';
        echo '<label>确认新密码<input type="password" name="new_admin_pass2" autocomplete="new-password" minlength="8"></label>';
        echo '</div></details>';

        echo '<button class="btn wide" style="margin-top:16px">保存设置</button></form>';

        echo '<script>(function(){'
            . 'var root=document.documentElement,hex=document.getElementById("themeHex"),acc=document.getElementById("themeAccent"),'
            . 'rng=document.getElementById("alphaRange"),out=document.getElementById("alphaOut"),chip=document.getElementById("colorChip");'
            . 'if(!hex||!rng)return;'
            . 'function rgb(h){h=h.replace("#","");return [parseInt(h.substr(0,2),16),parseInt(h.substr(2,2),16),parseInt(h.substr(4,2),16)];}'
            . 'function hx(a){return "#"+a.map(function(v){v=Math.max(0,Math.min(255,Math.round(v)));return ("0"+v.toString(16)).slice(-2);}).join("");}'
            . 'function mix(a,b,t){var x=rgb(a),y=rgb(b);return hx([x[0]+(y[0]-x[0])*t,x[1]+(y[1]-x[1])*t,x[2]+(y[2]-x[2])*t]);}'
            . 'function rgbs(h,al){var c=rgb(h);return "rgba("+c[0]+","+c[1]+","+c[2]+","+al+")";}'
            . 'function apply(p,a,al){var base=mix(p,"#0b1410",0.8);'
            . 'root.style.setProperty("--primary",p);root.style.setProperty("--primary-hover",mix(p,"#ffffff",0.1));'
            . 'root.style.setProperty("--accent",a);root.style.setProperty("--accent-soft",rgbs(a,0.18));'
            . 'root.style.setProperty("--card",rgbs(base,al));root.style.setProperty("--card-hover",rgbs(base,Math.min(1,al+0.08)));'
            . 'root.style.setProperty("--card-border",rgbs(a,0.18));root.style.setProperty("--field-border",rgbs(a,0.24));'
            . 'root.style.setProperty("--line",rgbs(a,0.14));}'
            . 'function mark(p){document.querySelectorAll(".sw").forEach(function(b){b.classList.toggle("on",b.dataset.p.toLowerCase()===p.toLowerCase());});}'
            . 'function sync(){var v=hex.value.trim();if(!/^#[0-9a-fA-F]{6}$/.test(v))return;var a=mix(v,"#ffffff",0.5);acc.value=a;'
            . 'chip.style.background=v;apply(v,a,rng.value/100);mark(v);}'
            . 'hex.addEventListener("input",sync);'
            . 'rng.addEventListener("input",function(){out.textContent=rng.value+"%";apply(hex.value,acc.value,rng.value/100);});'
            . 'document.querySelectorAll(".sw").forEach(function(b){b.addEventListener("click",function(){hex.value=b.dataset.p;acc.value=b.dataset.a;'
            . 'chip.style.background=b.dataset.p;apply(b.dataset.p,b.dataset.a,rng.value/100);mark(b.dataset.p);});});'
            . '})();</script>';

        $ust  = upd_state();
        $uchk = upd_cached();
        $uNew = $uchk ? upd_has_new($ust, $uchk) : false;
        echo '<div class="panel" style="margin-top:16px">';
        echo '<h3>系统更新</h3>';
        echo '<p class="muted small" style="margin:0 0 10px">当前版本 v' . e($ust['version'])
            . ($ust['sha'] !== '' ? ' · ' . e(substr($ust['sha'], 0, 7)) : '')
            . ($ust['time'] !== '' ? ' · 更新于 ' . e($ust['time']) : '') . '</p>';
        if ($uchk) {
            echo '<p class="small" style="margin:0 0 8px">GitHub 最新：<b>v' . e($uchk['version']) . '</b>'
                . ($uchk['sha'] !== '' ? ' · ' . e(substr($uchk['sha'], 0, 7)) : '')
                . ($uchk['date'] !== '' ? ' · ' . e(substr(str_replace('T', ' ', (string)$uchk['date']), 0, 16)) : '') . '</p>';
            if (!empty($uchk['note'])) {
                echo '<p class="muted small" style="margin:0 0 8px">' . e((string)$uchk['note']) . '</p>';
            }
            echo '<p class="small" style="margin:0 0 10px;color:' . ($uNew ? '#7fbf95' : '#9fb0a6') . '">'
                . ($uNew ? '发现新版本，可以更新' : '当前已是最新版本') . '</p>';
        } else {
            echo '<p class="muted small" style="margin:0 0 10px">还没有检查过，点下面的按钮获取 GitHub 最新版本。</p>';
        }
        echo '<div class="actions" style="margin:0">';
        echo '<form method="post" style="margin:0"><input type="hidden" name="act" value="check_update">'
            . csrf_field() . '<button class="btn ghost">检查更新</button></form>';
        if ($uNew) {
            echo '<form method="post" style="margin:0" data-confirm="将从 GitHub 拉取最新代码，更新前会自动备份当前代码。站点配置和数据库不受影响，确定继续？">'
                . '<input type="hidden" name="act" value="do_update">' . csrf_field()
                . '<button class="btn">立即更新到最新版</button></form>';
        }
        echo '</div>';
        echo '<p class="muted small" style="margin:12px 0 0">更新只覆盖程序代码，不会动 <code>app/config.php</code>、数据库和已上传文件；更新前的代码备份在 <code>data/backups/</code>。</p>';
        echo '</div>';

        echo '<div class="panel" style="margin-top:16px">';
        echo '<h3>账号</h3>';
        echo '<p class="muted small" style="margin:0 0 12px">退出后需要重新输入管理员密码才能进入后台。</p>';
        echo '<a class="btn ghost" href="/admin/logout" style="border-color:rgba(239,68,68,.4);color:#f2c9c4">退出登录</a>';
        echo '</div>';

        echo '<div class="alert warn small">服务器（Hiddify 面板）在 <a href="/admin/servers">服务器管理</a> 中添加，可无限添加多台。</div>';
    });
}

elseif ($path === '/admin/plans') {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'del') {
            db()->prepare("DELETE FROM plans WHERE id=?")->execute([(int)$_POST['id']]);
        } elseif ($act === 'toggle') {
            db()->prepare("UPDATE plans SET enabled=1-enabled WHERE id=?")->execute([(int)$_POST['id']]);
        }
        flash('已保存'); redirect('/admin/plans');
    }
    $plans = db()->query("SELECT * FROM plans ORDER BY sort,id")->fetchAll();
    page('套餐管理', function () use ($plans) {
        echo '<div class="actions" style="justify-content:space-between;align-items:center">';
        echo '<h2 style="margin:0">套餐管理</h2>';
        echo '<a class="btn" href="/admin/plans/new">新增套餐</a></div>';
        if (!$plans) { echo '<div class="panel"><p class="muted">还没有套餐，点右上角「新增套餐」。</p></div>'; return; }
        echo '<table><tr><th>ID</th><th>名称</th><th>范围</th><th>天数</th><th>流量GB</th><th>价格</th><th>状态</th><th></th></tr>';
        foreach ($plans as $p) {
            $isTop = (($p['kind'] ?? 'normal') === 'topup');
            $sc = $isTop ? '<span class="tag">加量包</span>'
                         : (($p['scope'] ?? 'single') === 'all' ? '<span class="tag provisioned">全地区</span>' : '<span class="tag">单地区</span>');
            echo '<tr><td>' . (int)$p['id'] . '</td><td>' . e($p['name']) . '</td><td>' . $sc . '</td><td>' . ($isTop ? '—' : e(days_label($p['days']))) . '</td>';
            echo '<td>' . (int)$p['traffic_gb'] . '</td><td>¥' . e(money((float)$p['price'])) . '</td>';
            echo '<td>' . ((int)$p['enabled'] ? '上架' : '下架') . '</td><td>';
            echo '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="btn xs">切换</button></form> ';
            echo '<form method="post" class="inline" data-confirm="确定删除这个套餐？">' . csrf_field() . '<input type="hidden" name="act" value="del"><input type="hidden" name="id" value="' . (int)$p['id'] . '"><button class="btn xs danger">删除</button></form>';
            echo '</td></tr>';
        }
        echo '</table>';
    });
}

elseif ($path === '/admin/plans/new') {
    require_admin();
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $name  = trim((string)($_POST['name'] ?? ''));
        $kind  = (($_POST['kind'] ?? 'normal') === 'topup') ? 'topup' : 'normal';
        $pDays = $kind === 'topup' ? 0 : (isset($_POST['no_expire']) ? 0 : max(1, (int)($_POST['days'] ?? 30)));
        $gb    = (int)($_POST['traffic_gb'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        if ($name === '')      $err = '套餐名称不能为空';
        elseif ($gb < 1)       $err = '流量必须大于 0';
        elseif ($price < 0)    $err = '价格不能为负数';
        else {
            $st = db()->prepare("INSERT INTO plans (name,days,traffic_gb,price,enabled,sort,badge,descr,scope,kind) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$name, $pDays, $gb, $price, isset($_POST['enabled']) ? 1 : 0, (int)($_POST['sort'] ?? 0),
                          trim((string)($_POST['badge'] ?? '')), trim((string)($_POST['descr'] ?? '')),
                          (($_POST['scope'] ?? 'single') === 'all' ? 'all' : 'single'), $kind]);
            flash('套餐已添加：' . $name);
            redirect('/admin/plans');
        }
    }
    page('新增套餐', function () use ($err) {
        $v = function ($k, $d = '') { return e((string)($_POST[$k] ?? $d)); };
        echo '<div class="actions" style="justify-content:space-between;align-items:center">';
        echo '<h2 style="margin:0">新增套餐</h2><a class="btn ghost" href="/admin/plans">返回套餐管理</a></div>';
        echo '<div class="panel">';
        if ($err !== '') echo '<div class="alert err">' . e($err) . '</div>';
        echo '<form method="post">' . csrf_field();
        echo '<script>function planKindUI(){var s=document.getElementById("plankind");if(!s)return;var t=s.value==="topup";'
           . 'document.getElementById("planNoExpireWrap").style.display=t?"none":"";'
           . 'document.getElementById("planDaysWrap").style.display=t?"none":"";'
           . 'document.getElementById("planScopeWrap").style.display=t?"none":"";'
           . 'var d=document.getElementById("plandays");if(d){d.disabled=t;d.required=!t;}}</script>';
        echo '<label>套餐类型<select name="kind" id="plankind" data-nice onchange="planKindUI()"><option value="normal"' . ((($_POST['kind'] ?? 'normal') === 'normal') ? ' selected' : '') . '>普通套餐（新开 / 续费）</option><option value="topup"' . ((($_POST['kind'] ?? '') === 'topup') ? ' selected' : '') . '>流量加量包（只加流量，不延长到期）</option></select></label>';
        echo '<div class="row2"><label>名称<input type="text" name="name" required value="' . $v('name') . '"></label><label>排序<input name="sort" type="number" value="' . $v('sort', '0') . '"></label></div>';
        echo '<label class="row" id="planNoExpireWrap" style="margin:8px 0"><input type="checkbox" name="no_expire" value="1"' . (isset($_POST['no_expire']) ? ' checked' : '') . ' onchange="var w=document.getElementById(\'planDaysWrap\'),d=document.getElementById(\'plandays\');w.style.display=this.checked?\'none\':\'\';w.parentElement.style.gridTemplateColumns=this.checked?\'1fr\':\'\';d.disabled=this.checked;d.required=!this.checked;"> 纯流量套餐（不设到期时间，流量用完为止）</label>';
        echo '<div class="row2"><label id="planDaysWrap">天数<input name="days" id="plandays" type="number" value="' . $v('days', '30') . '" required></label><label>流量GB<input name="traffic_gb" type="number" value="' . $v('traffic_gb', '100') . '" required></label></div>';
        echo '<div class="row2"><label>价格<input name="price" type="number" step="0.01" value="' . $v('price', '9.90') . '" required></label><label>角标<input type="text" name="badge" placeholder="推荐" value="' . $v('badge') . '"></label></div>';
        echo '<label id="planScopeWrap">节点范围<select name="scope" data-nice><option value="single"' . ((($_POST['scope'] ?? 'single') === 'single') ? ' selected' : '') . '>单地区（用户选择一个地区）</option><option value="all"' . ((($_POST['scope'] ?? '') === 'all') ? ' selected' : '') . '>全地区（包含所有服务器，一个订阅链接）</option></select></label>';
        echo '<label>描述<input type="text" name="descr" placeholder="100GB / 30天，纯流量填 100GB / 不限时" value="' . $v('descr') . '"></label>';
        echo '<label class="row"><input type="checkbox" name="enabled" value="1" checked> 立即上架</label>';
        echo '<div class="actions"><button class="btn">保存套餐</button><a class="btn ghost" href="/admin/plans">取消</a></div>';
        echo '</form></div>';
    });
}

elseif (preg_match('#^/admin/orders/([A-Za-z0-9]+)/edit$#', $path, $m)) {
    require_admin();
    $o = get_order($m[1]);
    if (!$o) { http_response_code(404); exit('订单不存在'); }
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $r = admin_update_order($o, $_POST);
        if (!$r['ok']) {
            $err = (string)$r['msg'];
        } else {
            flash('已保存：' . (int)$r['updated'] . ' 个面板账号已同步'
                . (empty($r['errors']) ? '' : '；失败：' . implode(' | ', $r['errors'])),
                empty($r['errors']) ? 'ok' : 'err');
            redirect('/admin/orders');
        }
        $o = get_order($m[1]) ?: $o;
    }
    $expDefault = trim((string)($o['expire_at'] ?? '')) !== ''
        ? date('Y-m-d', strtotime((string)$o['expire_at']))
        : date('Y-m-d', (strtotime((string)$o['paid_at']) ?: time()) + (int)$o['days'] * 86400);
    $accs = order_accounts((int)$o['id']);
    page('编辑用户', function () use ($o, $accs, $err, $expDefault) {
        echo '<div class="panel narrow"><h2>编辑用户 · ' . e((string)$o['order_no']) . '</h2>';
        echo '<p class="muted small">' . e((string)$o['plan_name']) . ' · ' . e((string)($o['region'] ?? '')) . '</p>';
        if ($err !== '') echo '<div class="alert err">' . e($err) . '</div>';
        echo '<form method="post">' . csrf_field();
        echo '<label>邮箱<input type="email" name="email" value="' . e((string)$o['email']) . '"></label>';
        echo '<label>备注<input type="text" name="note" value="' . e((string)($o['admin_note'] ?? '')) . '" placeholder="内部备注（同步为面板备注）"></label>';
        echo '<div class="row2"><label>流量上限（GB，0=不限）<input type="number" step="0.1" min="0" name="limit_gb" value="' . e((string)(float)$o['traffic_gb']) . '"></label>';
        echo '<label>到期时间<input type="date" data-picker name="expire_at" value="' . e($expDefault) . '"></label></div>';
        echo '<label class="row"><input type="checkbox" name="enabled" value="1"' . ((int)($o['panel_enable'] ?? 1) ? ' checked' : '') . '> 启用（取消勾选 = 停用，面板账号同时禁用）</label>';
        echo '<div class="actions"><button class="btn">保存并同步</button><a class="btn ghost" href="/admin/orders">返回</a></div>';
        echo '</form>';
        if ($accs) {
            echo '<div class="muted small" style="margin-top:18px">面板账号</div>';
            echo '<table><tr><th>地区</th><th>UUID</th><th>已用</th></tr>';
            foreach ($accs as $a) {
                echo '<tr><td>' . e((string)$a['region']) . '</td><td class="mono small">' . e((string)$a['uuid']) . '</td><td>' . e(number_format((float)$a['usage_gb'], 2)) . ' GB</td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';
    });
}

elseif ($path === '/admin/orders') {
    require_admin();
    expire_stale_orders();

    $q  = trim((string)($_GET['q'] ?? ''));
    $st = (string)($_GET['st'] ?? '');
    $d1 = (string)($_GET['d1'] ?? '');
    $d2 = (string)($_GET['d2'] ?? '');
    $w = []; $args = [];
    if ($q !== '') {
        $w[] = "(order_no LIKE ? OR email LIKE ? OR plan_name LIKE ?)";
        $like = '%' . $q . '%';
        $args[] = $like; $args[] = $like; $args[] = $like;
    }
    if (in_array($st, ['pending', 'paid', 'provisioned', 'failed', 'expired'], true)) { $w[] = "status=?"; $args[] = $st; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) { $w[] = "created_at >= ?"; $args[] = $d1 . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) { $w[] = "created_at <= ?"; $args[] = $d2 . ' 23:59:59'; }
    $where = $w ? (' WHERE ' . implode(' AND ', $w)) : '';

    $cntSt = db()->prepare("SELECT COUNT(*) FROM orders" . $where);
    $cntSt->execute($args);
    $total = (int)$cntSt->fetchColumn();

    $stq = db()->prepare("SELECT * FROM orders" . $where . " ORDER BY id DESC LIMIT 200");
    $stq->execute($args);
    $rows = $stq->fetchAll();

    $qs = http_build_query(array_filter(['q' => $q, 'st' => $st, 'd1' => $d1, 'd2' => $d2], fn($v) => $v !== ''));

    page('订单管理', function () use ($rows, $total, $q, $st, $d1, $d2, $qs) {
        echo '<div class="actions" style="justify-content:space-between;align-items:center"><h2 style="margin:0">订单管理</h2>';
        echo '<span class="muted small">共 ' . (int)$total . ' 条' . ($total > 200 ? '（最新 200 条）' : '') . '</span></div>';

        echo '<form method="get" action="/admin/orders" class="panel filterbar">';
        echo '<label>关键词<input type="text" name="q" value="' . e($q) . '" placeholder="订单号 / 邮箱 / 套餐"></label>';
        echo '<label>状态<select name="st" data-nice><option value="">全部状态</option>';
        foreach (['pending' => '待付款', 'paid' => '已付款', 'provisioned' => '已开通', 'failed' => '支付失败', 'expired' => '已失效'] as $k => $v) {
            echo '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . $v . '</option>';
        }
        echo '</select></label>';
        echo '<label>开始日期<input type="date" name="d1" data-picker value="' . e($d1) . '"></label>';
        echo '<label>结束日期<input type="date" name="d2" data-picker value="' . e($d2) . '"></label>';
        echo '<div class="actions" style="margin:6px 0 0"><button class="btn">筛选</button><a class="btn ghost" href="/admin/orders">重置</a></div>';
        echo '</form>';

        if (!$rows) { echo '<div class="panel"><p class="muted">没有符合条件的订单。</p></div>'; return; }

        echo '<table><tr><th>订单号</th><th>套餐</th><th>地区</th><th>金额</th><th>邮箱</th><th>状态</th><th>最近使用</th><th>时间</th><th>操作</th></tr>';
        $stMap = ['pending' => '待付款', 'paid' => '已付款', 'provisioned' => '已开通', 'failed' => '支付失败', 'expired' => '已失效', 'refunded' => '已退款'];
        foreach ($rows as $o) {
            echo '<tr><td class="mono">' . e($o['order_no']) . '</td><td>' . e($o['plan_name']) . '</td>';
            echo '<td>' . e($o['region'] ?? '') . '</td>';
            echo '<td>¥' . e(money((float)$o['amount'])) . '</td><td>' . e($o['email']) . '</td>';
            echo '<td><span class="tag ' . e($o['status']) . '">' . e($stMap[$o['status']] ?? $o['status']) . '</span>';
            if ($o['status'] === 'pending') {
                $ts = (strtotime((string)$o['created_at']) ?: time()) + ORDER_TTL;
                echo '<div class="small cdwrap">剩余 <span class="cd" data-ts="' . (int)$ts . '">--</span></div>';
            }
            echo '</td>';
            echo '<td class="small">' . e(ago_text((string)($o['last_seen'] ?? ''))) . '</td>';
            echo '<td class="small">' . e($o['created_at']) . '</td><td>';
            if (in_array($o['status'], ['pending', 'expired'], true)) {
                echo '<form method="post" action="/admin/order/' . (int)$o['id'] . '" class="inline">' . csrf_field();
                echo '<input type="hidden" name="act" value="paid"><button class="btn xs">标记已付</button></form> ';
            }
            if ($o['status'] === 'provisioned') {
                $subHref = trim((string)($o['sub_token'] ?? '')) !== '' ? '/s/' . $o['sub_token'] : '/order/' . $o['order_no'];
                echo '<a class="btn xs ghost" target="_blank" href="' . e($subHref) . '">查看</a>';
                if ((int)($o['sub_id'] ?? 0) > 0) {
                    echo ' <a class="btn xs ghost" href="/admin/subs/' . (int)$o['sub_id'] . '/edit">编辑</a>';
                } else {
                    echo ' <a class="btn xs ghost" href="/admin/orders/' . e((string)$o['order_no']) . '/edit">编辑</a>';
                }
                echo ' ';
            }
            if (in_array($o['status'], ['paid','failed'], true)) {
                echo '<form method="post" action="/admin/order/' . (int)$o['id'] . '" class="inline">' . csrf_field();
                echo '<input type="hidden" name="act" value="retry"><button class="btn xs">重试开通</button></form> ';
            }
            echo '<form method="post" action="/admin/order/' . (int)$o['id'] . '" class="inline" data-confirm="删除这条订单记录？面板账号和订阅不受影响。">' . csrf_field();
            echo '<input type="hidden" name="act" value="del"><input type="hidden" name="back" value="' . e($qs) . '">';
            echo '<button class="btn xs danger">删除</button></form>';
            echo '</td></tr>';
        }
        echo '</table>';
    });
}

elseif (preg_match('#^/admin/order/(\d+)$#', $path, $m)) {
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $id = (int)$m[1];
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'paid') {
            $o = get_order_by_id($id);
            if ($o) mark_paid($o['order_no'], 'MANUAL', 'manual');
        } elseif ($act === 'retry') {
            db()->prepare("UPDATE orders SET status='paid' WHERE id=?")->execute([$id]);
            provision_order($id);
        } elseif ($act === 'del') {
            db()->prepare("DELETE FROM order_accounts WHERE order_id=?")->execute([$id]);
            db()->prepare("DELETE FROM orders WHERE id=?")->execute([$id]);
        }
        flash($act === 'del' ? '订单已删除' : '操作完成');
    }
    $back = trim((string)($_POST['back'] ?? ''));
    redirect('/admin/orders' . ($back !== '' ? '?' . $back : ''));
}

elseif ($path === '/tutorial') {
    page('使用教程', function () {
        echo '<section class="hero" style="padding-bottom:4px"><h1>使用教程</h1>';
        echo '<p class="sub">三步搞定：下载客户端 → 导入订阅 → 一键连接</p></section>';

        $dlWin  = tut_dl('Hiddify-Windows-Setup-x64.exe', 'https://github.com/hiddify/hiddify-app/releases/latest/download/Hiddify-Windows-Setup-x64.exe');
        $dlApk  = tut_dl('Hiddify-Android-universal.apk', 'https://github.com/hiddify/hiddify-app/releases/latest/download/Hiddify-Android-universal.apk');
        $dlMac  = tut_dl('Hiddify-MacOS.dmg', 'https://github.com/hiddify/hiddify-app/releases/latest/download/Hiddify-MacOS.dmg');
        $local  = tut_dl_local('Hiddify-Windows-Setup-x64.exe');
        echo '<section class="panel" style="margin-top:26px">';
        echo '<h2 class="tut-h2"><span class="num">1</span> 下载客户端</h2>';
        echo '<p class="muted small" style="margin:6px 0 0">选择你正在使用的设备下载安装，装好后看第 3 步导入订阅。</p>';
        echo '<div class="tutdl">';
        echo '<div class="dlcard"><div class="os">🪟 Windows</div><div class="meta">安装版 · x64 · 约 49 MB</div>'
           . '<a class="btn" href="' . e($dlWin) . '"' . ($local ? ' download' : ' target="_blank" rel="noopener"') . '>下载 Windows 版</a></div>';
        echo '<div class="dlcard"><div class="os">🤖 Android</div><div class="meta">通用版 · Android 7.0+ · 约 180 MB</div>'
           . '<a class="btn" href="' . e($dlApk) . '"' . ($local ? ' download' : ' target="_blank" rel="noopener"') . '>下载安卓版</a></div>';
        echo '<div class="dlcard"><div class="os">🍎 macOS</div><div class="meta">DMG · Intel / Apple 芯片 · 约 120 MB</div>'
           . '<a class="btn" href="' . e($dlMac) . '"' . ($local ? ' download' : ' target="_blank" rel="noopener"') . '>下载 Mac 版</a></div>';
        echo '<div class="dlcard"><div class="os">📱 iPhone / iPad</div><div class="meta">App Store · 需要 iOS 15 或更高</div>'
           . '<a class="btn" href="https://apps.apple.com/us/app/hiddify-proxy-vpn/id6596777532" target="_blank" rel="noopener">前往 App Store</a></div>';
        echo '</div></section>';

        echo '<section class="panel" style="margin-top:18px">';
        echo '<h2 class="tut-h2"><span class="num">2</span> 拿到你的订阅链接</h2>';
        echo '<p>付款成功后，订阅链接会自动发到你下单时填写的邮箱，点开邮件里的链接即可复制。</p>';
        echo '<p>忘了也没关系，点这里随时查：<a class="btn ghost sm" href="/query" style="margin-left:6px">我的订阅</a></p>';
        echo '<p class="muted small">订阅链接相当于你的账号密码，不要发给别人，泄露了请联系客服重置。</p>';
        echo '</section>';

        echo '<section class="panel" style="margin-top:18px">';
        echo '<h2 class="tut-h2"><span class="num">3</span> 导入订阅</h2>';

        echo '<div class="tut-os">Windows</div><ol class="tut-ol">';
        echo '<li>双击安装包，按提示完成安装，打开 Hiddify。</li>';
        echo '<li>点左上角的 <b>+</b>，选择「从剪贴板添加」。</li>';
        echo '<li>粘贴订阅链接，点「添加 / 保存」。</li></ol>';

        echo '<div class="tut-os">macOS</div><ol class="tut-ol">';
        echo '<li>打开下载的 DMG，把 Hiddify 图标拖进「应用程序」文件夹。</li>';
        echo '<li>第一次打开如果提示「无法验证开发者」，在应用上点右键 → 打开 → 再点「打开」。</li>';
        echo '<li>点左上角的 <b>+</b> →「从剪贴板添加」，粘贴订阅链接后保存。</li></ol>';

        echo '<div class="tut-os">Android</div><ol class="tut-ol">';
        echo '<li>点上面的「下载安卓版」，安装时如果提示「未知来源」，允许本次安装即可。</li>';
        echo '<li>打开 App，点右下角的 <b>+</b> →「从剪贴板导入」。</li>';
        echo '<li>粘贴订阅链接，点保存，配置会出现在首页列表。</li></ol>';

        echo '<div class="tut-os">iPhone / iPad</div><ol class="tut-ol">';
        echo '<li>点「前往 App Store」，搜索 <b>Hiddify</b> 下载安装（需要 iOS 15 或更高）。中国区商店搜不到的话，换美区 / 港区 Apple ID 登录后再搜。</li>';
        echo '<li>打开 App，点右上角或列表下方的 <b>+</b> →「从剪贴板添加」，粘贴订阅链接后保存。</li>';
        echo '<li>点连接时系统会弹出「Hiddify 想要添加 VPN 配置」，点「允许」，验证 Face ID 或锁屏密码即可。</li></ol>';
        echo '</section>';

        echo '<section class="panel" style="margin-top:18px">';
        echo '<h2 class="tut-h2"><span class="num">4</span> 选择节点并连接</h2><ol class="tut-ol">';
        echo '<li>在列表里挑一个延迟低的节点（点节点右边的闪电图标可以测速，数字越小越快）。</li>';
        echo '<li>点中间的圆形大按钮开始连接，第一次会弹出「VPN 连接请求」，点「允许 / 确定」。</li>';
        echo '<li>按钮变成已连接状态后，打开浏览器试试能不能访问 Google、YouTube。</li></ol>';
        echo '<p class="muted small">电脑端建议开启「系统代理 / TUN 模式」，手机端直接用即可。</p>';
        echo '</section>';

        echo '<section class="panel tut-faq" style="margin-top:18px">';
        echo '<h2>常见问题</h2>';
        echo '<details><summary>连不上或一直转圈？</summary><p>先换一个节点再试；确认订阅还有流量、没有到期；公司或学校网络可能封锁，换手机热点试试。</p></details>';
        echo '<details><summary>换了新节点，列表没更新？</summary><p>在首页下拉刷新，或点配置右边的刷新按钮重新拉取订阅。</p></details>';
        echo '<details><summary>一个订阅能同时几台设备？</summary><p>以你购买的套餐说明为准。同一时间设备过多可能触发限速。</p></details>';
        echo '<details><summary>速度慢怎么办？</summary><p>优先选延迟低的节点；晚高峰可以多试几个地区；下载大文件建议用电脑端。</p></details>';
        echo '<details><summary>订阅链接能发朋友吗？</summary><p>不建议。链接就是账号凭证，发给别人会占用你的流量，也可能导致账号被封。</p></details>';
        echo '</section>';

        echo '<p style="text-align:center;margin:28px 0 8px"><a class="btn" href="/">返回首页选购套餐</a></p>';
    });
}
else {
    http_response_code(404);
    page('404', function () { echo '<div class="panel"><h2>页面不存在</h2><p><a href="/">返回首页</a></p></div>'; });
}
