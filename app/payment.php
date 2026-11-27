<?php
declare(strict_types=1);

require_once __DIR__ . '/submerge.php';
require_once __DIR__ . '/pool.php';

/* ================= 易支付 ================= */

function epay_conf(): array {
    return ['url' => rtrim(cfg('epay_url'), '/'), 'pid' => trim(cfg('epay_pid')), 'key' => trim(cfg('epay_key'))];
}
function epay_enabled(): bool {
    $c = epay_conf();
    return $c['url'] !== '' && $c['pid'] !== '' && $c['key'] !== '';
}
function test_mode(): bool { return cfg('test_mode', '1') === '1'; }

function epay_sign(array $params, string $key): string {
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        if ($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) continue;
        $pairs[] = $k . '=' . $v;
    }
    return md5(implode('&', $pairs) . $key);
}
function epay_pay_url(array $order, string $payType): string {
    $c = epay_conf(); $base = site_url();
    $params = [
        'pid' => $c['pid'], 'out_trade_no' => $order['order_no'],
        'notify_url' => $base . '/notify', 'return_url' => $base . '/return',
        'name' => '商品购物', 'money' => money((float)$order['amount']),
    ];
    // 传空表示不在本站选支付宝/微信，直接进易支付自己的收银台选
    if ($payType !== '') $params['type'] = $payType;
    $params['sign'] = epay_sign($params, $c['key']);
    $params['sign_type'] = 'MD5';
    return $c['url'] . '/submit.php?' . http_build_query($params);
}
function epay_verify(array $params): bool {
    $c = epay_conf();
    if (empty($params['sign'])) return false;
    $sign = (string)$params['sign'];
    unset($params['sign'], $params['sign_type']);
    return hash_equals(epay_sign($params, $c['key']), $sign);
}

/* ================= 站点 ================= */
function site_url(): string {
    $configured = rtrim(cfg('site_url'), '/');
    if ($configured !== '') return $configured;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* ================= 订单 ================= */
/** 未付款订单有效期：24 小时 */
const ORDER_TTL = 86400;

/** 批量把超时未付款的订单标记为已失效（后台仍可见，前台查询不再显示） */
function expire_stale_orders(): int {
    $cut = date('Y-m-d H:i:s', time() - ORDER_TTL);
    $st = db()->prepare("UPDATE orders SET status='expired' WHERE status='pending' AND created_at<>'' AND created_at <= ?");
    $st->execute([$cut]);
    return $st->rowCount();
}

function order_is_stale(array $o): bool {
    if (($o['status'] ?? '') !== 'pending') return false;
    $t = strtotime((string)($o['created_at'] ?? ''));
    return $t > 0 && $t + ORDER_TTL <= time();
}

/** 取订单时顺手判超时，任何页面看到的都是真实状态 */
function order_expire_if_stale(array $o): array {
    if (order_is_stale($o)) {
        db()->prepare("UPDATE orders SET status='expired' WHERE id=? AND status='pending'")->execute([(int)$o['id']]);
        $o['status'] = 'expired';
    }
    return $o;
}

function get_order(string $orderNo): ?array {
    $st = db()->prepare("SELECT * FROM orders WHERE order_no = ?"); $st->execute([$orderNo]);
    $o = $st->fetch();
    return $o ? order_expire_if_stale($o) : null;
}
function get_order_by_id(int $id): ?array {
    $st = db()->prepare("SELECT * FROM orders WHERE id = ?"); $st->execute([$id]);
    $o = $st->fetch();
    return $o ? order_expire_if_stale($o) : null;
}
function get_order_by_token(string $token): ?array {
    if ($token === '') return null;
    $st = db()->prepare("SELECT * FROM orders WHERE sub_token = ? AND status='provisioned'"); $st->execute([$token]);
    return $st->fetch() ?: null;
}
function order_accounts(int $orderId): array {
    $st = db()->prepare("SELECT * FROM order_accounts WHERE order_id=? ORDER BY id"); $st->execute([$orderId]);
    return $st->fetchAll();
}
/** 加量包前置检查：该邮箱有没有可充值的订阅 */
function topup_check(string $email): array {
    $sub = get_sub_by_email($email);
    if ($sub) {
        if (strtotime((string)$sub['expire_at']) < time()) return ['ok' => false, 'msg' => '该订阅已到期，请先续费再买加量包'];
        return ['ok' => true, 'type' => 'pool'];
    }
    $st = db()->prepare("SELECT * FROM orders WHERE LOWER(email)=? AND status='provisioned' AND note='single' ORDER BY id DESC LIMIT 1");
    $st->execute([strtolower($email)]);
    $prev = $st->fetch();
    if (!$prev) return ['ok' => false, 'msg' => '这个邮箱还没有订阅，请先购买套餐'];
    $exp = trim((string)($prev['expire_at'] ?? ''));
    $ts  = $exp !== '' ? (strtotime($exp) ?: 0)
                       : ((strtotime((string)($prev['paid_at'] ?: $prev['created_at'])) ?: 0) + (int)$prev['days'] * 86400);
    if ($ts > 0 && $ts < time()) return ['ok' => false, 'msg' => '该订阅已到期，请先续费再买加量包'];
    if ((float)$prev['traffic_gb'] <= 0) return ['ok' => false, 'msg' => '原订阅是不限量套餐，无需加量'];
    return ['ok' => true, 'type' => 'single'];
}

function plan_kind(int $planId): string {
    $st = db()->prepare("SELECT kind FROM plans WHERE id=?");
    $st->execute([$planId]);
    return $st->fetchColumn() === 'topup' ? 'topup' : 'normal';
}

/**
 * 流量加量包：只加流量，不改变到期时间
 * 优先加到该邮箱的全地区共享池；没有池就加到最近一个单地区订阅上
 */
function provision_topup(array $o): array {
    $email   = trim((string)$o['email']);
    $gb      = (float)$o['traffic_gb'];
    $orderId = (int)$o['id'];
    if ($gb <= 0) return ['ok' => false, 'msg' => '加量流量不合法'];

    // 1) 全地区共享池
    $sub = get_sub_by_email($email);
    if ($sub) {
        $expired = strtotime((string)$sub['expire_at']) < time();
        if ($expired) return ['ok' => false, 'msg' => '该订阅已到期，请先续费再买加量包'];
        extend_sub($sub, $gb, 0); // days=0：只加流量，到期不变
        try { pool_sync((int)$sub['id']); } catch (Throwable $e) { slog('warn', 'topup pool sync: ' . $e->getMessage()); }
        db()->prepare("UPDATE orders SET status='provisioned', paid_at=CASE WHEN paid_at='' THEN ? ELSE paid_at END,
                       note='topup', sub_id=?, sub_token=?, sub_url=? WHERE id=?")
            ->execute([now(), (int)$sub['id'], (string)$sub['token'], site_url() . '/sub/' . $sub['token'], $orderId]);
        slog('info', "order {$o['order_no']} topup pool sub#{$sub['id']} +{$gb}GB");
        return ['ok' => true, 'msg' => '已加 ' . number_format($gb, 2) . ' GB 到共享池，到期时间不变'];
    }

    // 2) 单地区：最近一个已开通订单
    $st = db()->prepare("SELECT * FROM orders WHERE LOWER(email)=? AND status='provisioned' AND note='single' ORDER BY id DESC LIMIT 1");
    $st->execute([strtolower($email)]);
    $prev = $st->fetch();
    if (!$prev) return ['ok' => false, 'msg' => '该邮箱没有可充值的订阅'];

    $prevExp = trim((string)($prev['expire_at'] ?? ''));
    $prevTs  = $prevExp !== '' ? (strtotime($prevExp) ?: 0)
                               : ((strtotime((string)($prev['paid_at'] ?: $prev['created_at'])) ?: 0) + (int)$prev['days'] * 86400);
    if ($prevTs > 0 && $prevTs < time()) return ['ok' => false, 'msg' => '该订阅已到期，请先续费再买加量包'];

    $srv = get_server((int)$prev['server_id']);
    if (!$srv) return ['ok' => false, 'msg' => '订阅所属服务器不存在'];
    try {
        $panel = HiddifyPanel::forServer($srv);
        $u = $panel->getUser((string)$prev['uuid']);
        $limit = (float)($u['usage_limit_GB'] ?? 0);
        if ($limit <= 0) return ['ok' => false, 'msg' => '原订阅是不限量套餐，无需加量'];
        $panel->updateUser((string)$prev['uuid'], ['usage_limit_GB' => $limit + $gb, 'enable' => true]);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '面板更新失败：' . $e->getMessage()];
    }
    db()->prepare("UPDATE orders SET traffic_gb=traffic_gb+? WHERE id=?")->execute([$gb, (int)$prev['id']]);
    db()->prepare("UPDATE orders SET status='provisioned', paid_at=CASE WHEN paid_at='' THEN ? ELSE paid_at END,
                   note='topup', uuid=?, sub_url=?, sub_token=? WHERE id=?")
        ->execute([now(), (string)$prev['uuid'], (string)$prev['sub_url'], (string)$prev['sub_token'], $orderId]);
    slog('info', "order {$o['order_no']} topup single order#{$prev['id']} +{$gb}GB");
    return ['ok' => true, 'msg' => '已加 ' . number_format($gb, 2) . ' GB 到原订阅，到期时间不变'];
}

function plan_scope(int $planId): string {
    $st = db()->prepare("SELECT scope FROM plans WHERE id=?"); $st->execute([$planId]);
    $v = $st->fetchColumn();
    return $v === 'all' ? 'all' : 'single';
}

function mark_paid(string $orderNo, string $tradeNo = '', string $method = ''): void {
    $st = db()->prepare("UPDATE orders SET status='paid', paid_at=?, trade_no=?, pay_method=CASE WHEN ?<>'' THEN ? ELSE pay_method END
                         WHERE order_no=? AND status IN ('pending','expired')");
    $st->execute([now(), $tradeNo, $method, $method, $orderNo]);
    // 原子抢占：只有把 pending 改成 paid 的那一次请求才去开通，避免重复回调重复建号
    if ($st->rowCount() === 0) return;
    $o = get_order($orderNo);
    if ($o) provision_order((int)$o['id']);
}

/**
 * 单地区订单的真实额度/到期/已用。
 * 同邮箱在同一台服务器续费时复用同一个面板账号（uuid 相同），
 * 每次续费的订单各占一行，这里按 uuid 合并，避免把一次账号算成多个订单。
 */
function single_effective(array $o): array {
    $rows = [$o];
    $u = (string)($o['uuid'] ?? '');
    if ($u !== '') {
        $st = db()->prepare("SELECT * FROM orders WHERE uuid=? AND note='single' AND status='provisioned'");
        $st->execute([$u]);
        $all = $st->fetchAll();
        if ($all) $rows = $all;
    }
    $quota = 0.0; $exp = 0; $used = 0.0;
    foreach ($rows as $r) {
        $quota += (float)$r['traffic_gb'];
        $used = max($used, (float)$r['used_gb']);
        $e = trim((string)($r['expire_at'] ?? '')) !== ''
            ? (strtotime((string)$r['expire_at']) ?: 0)
            : ((strtotime((string)($r['paid_at'] ?: $r['created_at'])) ?: 0) + (int)$r['days'] * 86400);
        if ($e > $exp) $exp = $e;
    }
    if ($quota <= 0) $quota = (float)$o['traffic_gb'];
    return ['quota' => $quota, 'expire' => $exp, 'used' => $used];
}

/* ================= 开通 ================= */

/** 找该邮箱在该服务器上最近一次的有效账号 */
function find_prev_account(string $email, int $serverId): ?array {
    $st = db()->prepare("SELECT oa.* FROM order_accounts oa JOIN orders o ON o.id=oa.order_id
                         WHERE LOWER(o.email)=? AND oa.server_id=? AND o.status='provisioned' AND oa.uuid<>''
                         ORDER BY oa.id DESC LIMIT 1");
    $st->execute([strtolower($email), $serverId]);
    return $st->fetch() ?: null;
}

/** 在单台服务器上开通/续费，返回 [uuid, sub_url, action] */
function provision_on_server(array $o, array $srv): array {
    $panel = HiddifyPanel::forServer($srv);
    $panel->login();

    $prev = find_prev_account((string)$o['email'], (int)$srv['id']);
    if ($prev) {
        try {
            $u = $panel->getUser((string)$prev['uuid']);
            if (!empty($u['uuid']) && empty($u['deleted'])) {
                $oldDays = (int)($u['package_days'] ?? 0);
                $startTs = strtotime((string)($u['start_date'] ?? date('Y-m-d'))) ?: time();
                $remain = max(0, (int)ceil((($startTs + $oldDays * 86400) - time()) / 86400));
                $newDays = $remain + (int)$o['days'];
                $newLimit = (float)($u['usage_limit_GB'] ?? 0) + (float)$o['traffic_gb'];
                $patch = ['package_days' => $newDays, 'start_date' => date('Y-m-d'), 'enable' => true];
                if ($newLimit > 0) $patch['usage_limit_GB'] = $newLimit;
                $panel->updateUser((string)$prev['uuid'], $patch);
                return ['uuid' => (string)$prev['uuid'], 'sub_url' => $panel->subUrl((string)$prev['uuid']), 'action' => 'renew'];
            }
        } catch (Throwable $e) {
            slog('warn', 'renew fallback: ' . $e->getMessage());
        }
    }

    $name = 'u' . substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)$o['email']), 0, 10) . '-' . substr((string)$o['order_no'], -4);
    $u = $panel->createUser($name, (int)$o['days'], (float)$o['traffic_gb'], (string)$o['email']);
    return ['uuid' => (string)$u['uuid'], 'sub_url' => $panel->subUrl((string)$u['uuid']), 'action' => 'new'];
}

function provision_order(int $orderId): array {
    $o = get_order_by_id($orderId);
    if (!$o) return ['ok' => false, 'msg' => '订单不存在'];
    if ($o['status'] === 'provisioned') return ['ok' => true, 'msg' => '已开通', 'sub_url' => $o['sub_url']];
    if (plan_kind((int)$o['plan_id']) === 'topup') return provision_topup($o);

    $scope = plan_scope((int)$o['plan_id']);
    if ($scope === 'all') return provision_pool($o);

    $srv = get_server((int)$o['server_id']);
    if (!$srv) $srv = all_servers(true)[0] ?? null;
    if (!$srv) return ['ok' => false, 'msg' => '没有可用的服务器'];

    try {
        $r = provision_on_server($o, $srv);
    } catch (Throwable $e) {
        db()->prepare("UPDATE orders SET status='failed', note=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(), 0, 500), $orderId]);
        db()->prepare("UPDATE servers SET last_status='fail', last_check=?, last_error=? WHERE id=?")
            ->execute([now(), mb_substr($e->getMessage(), 0, 500), (int)$srv['id']]);
        slog('error', "order {$o['order_no']} provision failed: " . $e->getMessage());
        return ['ok' => false, 'msg' => $e->getMessage()];
    }

    $token = $o['sub_token'] !== '' ? $o['sub_token'] : bin2hex(random_bytes(16));
    db()->prepare("INSERT INTO order_accounts (order_id,server_id,uuid,sub_url,region) VALUES (?,?,?,?,?)")
        ->execute([$orderId, (int)$srv['id'], $r['uuid'], $r['sub_url'], server_region($srv)]);
    db()->prepare("UPDATE orders SET status='provisioned', uuid=?, sub_url=?, sub_token=?, note=? WHERE id=?")
        ->execute([$r['uuid'], $r['sub_url'], $token, 'single', $orderId]);
    db()->prepare("UPDATE servers SET last_status='ok', last_check=?, last_error='' WHERE id=?")
        ->execute([now(), (int)$srv['id']]);
    slog('info', "order {$o['order_no']} provisioned single server#{$srv['id']}");
    return ['ok' => true, 'msg' => '开通成功', 'sub_url' => $r['sub_url']];
}

/** 全地区套餐：一个共享流量池，按邮箱聚合，所有服务器账号共用一个总流量 */
function provision_pool(array $o): array {
    $orderId = (int)$o['id'];
    $servers = all_servers(true);
    if (!$servers) return ['ok' => false, 'msg' => '没有可用的服务器'];

    // 1) 池：同邮箱复用，续费叠加额度与天数
    $sub = get_sub_by_email((string)$o['email']);
    if ($sub) {
        $sub = extend_sub($sub, (float)$o['traffic_gb'], (int)$o['days']);
    } else {
        $sub = create_sub((string)$o['email'], (float)$o['traffic_gb'], (int)$o['days']);
    }
    $quota = (float)$sub['quota_gb'];
    $used = (float)$sub['used_gb'];
    $remainDays = max(1, (int)ceil((strtotime((string)$sub['expire_at']) - time()) / 86400));

    $done = []; $errs = [];
    foreach ($servers as $srv) {
        try {
            $panel = HiddifyPanel::forServer($srv);
            $panel->login();

            $st = db()->prepare("SELECT * FROM sub_accounts WHERE sub_id=? AND server_id=?");
            $st->execute([(int)$sub['id'], (int)$srv['id']]);
            $acc = $st->fetch();

            // quota=0 表示不限量，面板限额传 0
            $allowance = $quota > 0 ? max(0.01, $quota - $used) : 0.0;

            if ($acc && $acc['uuid'] !== '') {
                try {
                    $u = $panel->getUser((string)$acc['uuid']);
                    if (!empty($u['uuid']) && empty($u['deleted'])) {
                        $panel->updateUser((string)$acc['uuid'], [
                            'usage_limit_GB' => round($allowance, 2),
                            'package_days'   => $remainDays,
                            'start_date'     => date('Y-m-d'),
                            'enable'         => true,
                        ]);
                        $subUrl = $panel->subUrl((string)$acc['uuid']);
                        db()->prepare("UPDATE sub_accounts SET sub_url=?, region=?, last_sync=? WHERE id=?")
                            ->execute([$subUrl, server_region($srv), now(), (int)$acc['id']]);
                        $done[] = ['region' => server_region($srv), 'uuid' => (string)$acc['uuid'], 'sub_url' => $subUrl, 'action' => 'renew'];
                        continue;
                    }
                } catch (Throwable $e) {
                    slog('warn', 'sub account missing, recreate: ' . $e->getMessage());
                }
            }

            $name = 'p' . substr(preg_replace('/[^a-zA-Z0-9]/', '', (string)$o['email']), 0, 10) . '-' . substr((string)$sub['token'], 0, 4);
            $u = $panel->createUser($name, $remainDays, $quota > 0 ? (float)ceil($allowance) : 0.0, (string)$o['email']);
            $uuid = (string)$u['uuid'];
            $subUrl = $panel->subUrl($uuid);

            if ($acc) {
                db()->prepare("UPDATE sub_accounts SET uuid=?, sub_url=?, region=?, last_sync=? WHERE id=?")
                    ->execute([$uuid, $subUrl, server_region($srv), now(), (int)$acc['id']]);
            } else {
                db()->prepare("INSERT INTO sub_accounts (sub_id,server_id,uuid,sub_url,region,last_sync) VALUES (?,?,?,?,?,?)")
                    ->execute([(int)$sub['id'], (int)$srv['id'], $uuid, $subUrl, server_region($srv), now()]);
            }
            $done[] = ['region' => server_region($srv), 'uuid' => $uuid, 'sub_url' => $subUrl, 'action' => 'new'];
            db()->prepare("UPDATE servers SET last_status='ok', last_check=?, last_error='' WHERE id=?")
                ->execute([now(), (int)$srv['id']]);
        } catch (Throwable $e) {
            $errs[] = server_region($srv) . '：' . $e->getMessage();
            db()->prepare("UPDATE servers SET last_status='fail', last_check=?, last_error=? WHERE id=?")
                ->execute([now(), mb_substr($e->getMessage(), 0, 500), (int)$srv['id']]);
            slog('error', "pool {$sub['token']} server#{$srv['id']} failed: " . $e->getMessage());
        }
    }

    if (!$done) {
        db()->prepare("UPDATE orders SET status='failed', note=? WHERE id=?")
            ->execute([mb_substr(implode(' | ', $errs), 0, 500), $orderId]);
        return ['ok' => false, 'msg' => implode('; ', $errs)];
    }

    $mergedUrl = site_url() . '/sub/' . $sub['token'];
    db()->prepare("UPDATE orders SET status='provisioned', uuid=?, sub_url=?, sub_token=?, sub_id=?, region=?, note=? WHERE id=?")
        ->execute([$done[0]['uuid'], $mergedUrl, $sub['token'], (int)$sub['id'],
                   '全地区（' . count($done) . '台）', 'pool', $orderId]);

    // 立刻同步一次，把额度按池剩余量分配给各账号
    try { pool_sync((int)$sub['id']); } catch (Throwable $e) { slog('warn', 'pool sync: ' . $e->getMessage()); }

    slog('info', "order {$o['order_no']} pool provisioned ok=" . count($done) . ' fail=' . count($errs));
    return ['ok' => true, 'msg' => '开通成功', 'sub_url' => $mergedUrl, 'accounts' => $done, 'errors' => $errs];
}

/**
 * 管理员手动加流量：直接建一张 0 元加量单并执行
 */
function admin_add_traffic(string $email, float $gb): array {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'msg' => '邮箱格式不正确'];
    if ($gb <= 0 || $gb > 1000000) return ['ok' => false, 'msg' => '加量流量不合法'];
    $no = 'T' . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
    db()->prepare("INSERT INTO orders (order_no,plan_id,plan_name,days,traffic_gb,amount,email,status,pay_method,trade_no,created_at,paid_at,note,region)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$no, 0, '手动加量', 0, $gb, 0, $email, 'paid', 'manual', 'MANUAL', now(), now(), 'topup', '加量包']);
    $o = get_order($no);
    if (!$o) return ['ok' => false, 'msg' => '订单创建失败'];
    $r = provision_topup($o);
    if (empty($r['ok'])) {
        // 没加成功就别留一张「已支付」的空单
        db()->prepare("UPDATE orders SET status='failed', note=? WHERE id=?")
            ->execute([mb_substr((string)($r['msg'] ?? '加量失败'), 0, 500), (int)$o['id']]);
    }
    $r['order_no'] = $no;
    return $r;
}

/**
 * 管理员手动开通（不走支付）：建订单 → 直接调用开通流程
 * $in: kind=all|single, email, plan_name, days, gb, server_id
 */
function admin_create_order(array $in): array {
    $email = trim((string)($in['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => '邮箱格式不正确'];
    }
    $kind = (($in['kind'] ?? 'all') === 'single') ? 'single' : 'all';
    $noExpire = !empty($in['no_expire']);
    $days = $noExpire ? NO_EXPIRE_DAYS : (int)($in['days'] ?? 0);
    if (!$noExpire && ($days < 1 || $days > 3650)) return ['ok' => false, 'msg' => '天数需在 1 - 3650 之间'];
    $gb = max(0.0, (float)($in['gb'] ?? 0));
    if ($gb > 1000000) return ['ok' => false, 'msg' => '流量数值过大'];

    $srv = null;
    if ($kind === 'single') {
        $srv = get_server((int)($in['server_id'] ?? 0));
        if (!$srv || !(int)$srv['enabled']) return ['ok' => false, 'msg' => '请选择一台可用的服务器'];
    }
    $planName = trim((string)($in['plan_name'] ?? ''));
    if ($planName === '') $planName = $kind === 'all' ? '全地区（手动开通）' : '手动开通';

    $no = 'A' . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
    db()->prepare("INSERT INTO orders (order_no,plan_id,plan_name,days,traffic_gb,amount,email,status,pay_method,trade_no,created_at,paid_at,note,server_id,region)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$no, 0, $planName, $days, $gb, 0, $email, 'paid', 'manual', 'MANUAL', now(), now(),
                   $kind === 'all' ? 'pool' : 'single', $srv ? (int)$srv['id'] : 0,
                   $kind === 'all' ? '全地区' : server_region($srv)]);

    $o = get_order($no);
    if (!$o) return ['ok' => false, 'msg' => '订单创建失败'];
    $r = $kind === 'all' ? provision_pool($o) : provision_order((int)$o['id']);
    $r['order_no'] = $no;
    return $r;
}

/* ---------- 支付渠道注册表：三大类 ---------- */
function pay_categories(): array {
    return [
        'epay' => [
            'name' => '易支付',
            'desc' => '第三方聚合支付，一套配置同时支持支付宝 / 微信',
            'methods' => [
                'epay' => ['name' => '易支付', 'note' => '跳转后选择支付宝 / 微信', 'mode' => 'redirect', 'ready' => epay_enabled()],
            ],
        ],
        'alipay' => [
            'name' => '支付宝官方',
            'desc' => '每种方式单独配置密钥',
            'methods' => [
                'alipay_page' => ['name' => '电脑网站支付', 'note' => 'PC 跳转', 'mode' => 'redirect', 'ready' => alipay_configured('alipay_page')],
                'alipay_wap'  => ['name' => '手机网站支付', 'note' => '手机浏览器跳转', 'mode' => 'redirect', 'ready' => alipay_configured('alipay_wap')],
                'alipay_qr'   => ['name' => '当面付', 'note' => '扫码支付', 'mode' => 'qr', 'ready' => alipay_configured('alipay_qr')],
            ],
        ],
        'wechat' => [
            'name' => '微信支付官方',
            'desc' => '每种场景单独配置',
            'methods' => [
                'wechat_native' => ['name' => 'Native 扫码', 'note' => 'PC 扫码', 'mode' => 'qr', 'ready' => wechat_configured('wechat_native')],
                'wechat_h5'     => ['name' => 'H5 支付', 'note' => '手机浏览器', 'mode' => 'redirect', 'ready' => wechat_configured('wechat_h5')],
                'wechat_jsapi'  => ['name' => 'JSAPI 支付', 'note' => '微信内打开', 'mode' => 'jsapi', 'ready' => wechat_configured('wechat_jsapi')],
            ],
        ],
    ];
}
/** 暂未开放、收银台只展示「开发中」的通道 */
function pay_coming_soon(): array {
    return ['alipay_page', 'alipay_wap', 'wechat_native', 'wechat_h5', 'wechat_jsapi'];
}
function pay_channels(): array {
    $flat = [];
    foreach (pay_categories() as $cat) foreach ($cat['methods'] as $id => $m) $flat[$id] = $m;
    return $flat;
}
function pay_enabled_ids(): array {
    $v = trim(setting('pay_channels', ''));
    if ($v === '') {
        // 只有从未保存过渠道设置的老站点才自动启用易支付；
        // 管理员在后台手动取消勾选并保存后（pay_channels_set=1）必须保持全部关闭。
        if (setting('pay_channels_set', '0') === '1') return [];
        return epay_enabled() ? ['epay'] : [];
    }
    $ids = array_values(array_filter(array_map('trim', explode(',', $v))));
    // 兼容旧数据：epay_alipay / epay_wxpay 合并为单一 epay
    if (in_array('epay_alipay', $ids, true) || in_array('epay_wxpay', $ids, true)) $ids[] = 'epay';
    $ids = array_values(array_unique(array_diff($ids, ['epay_alipay', 'epay_wxpay'])));
    return $ids;
}
function pay_available(): array {
    $ids = pay_enabled_ids();
    $out = [];
    foreach (pay_channels() as $id => $c) {
        if ($c['ready'] && in_array($id, $ids, true)) $out[$id] = $c;
    }
    return $out;
}
