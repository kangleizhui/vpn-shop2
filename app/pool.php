<?php
declare(strict_types=1);

/* ================= 共享流量池 ================= */

function get_sub(int $id): ?array {
    $st = db()->prepare("SELECT * FROM subs WHERE id=?"); $st->execute([$id]);
    return $st->fetch() ?: null;
}
function get_sub_by_token(string $token): ?array {
    $st = db()->prepare("SELECT * FROM subs WHERE token=?"); $st->execute([$token]);
    return $st->fetch() ?: null;
}
function get_sub_by_email(string $email): ?array {
    $email = strtolower(trim($email));
    $st = db()->prepare("SELECT * FROM subs WHERE LOWER(email)=? ORDER BY id DESC LIMIT 1"); $st->execute([$email]);
    return $st->fetch() ?: null;
}
function sub_accounts(int $subId): array {
    $st = db()->prepare("SELECT * FROM sub_accounts WHERE sub_id=? ORDER BY id"); $st->execute([$subId]);
    return $st->fetchAll();
}

function create_sub(string $email, float $quotaGb, int $days): array {
    $token = bin2hex(random_bytes(16));
    $expire = date('Y-m-d H:i:s', time() + $days * 86400);
    $st = db()->prepare("INSERT INTO subs (token,email,quota_gb,used_gb,expire_at,status,created_at) VALUES (?,?,?,0,?,'active',?)");
    $st->execute([$token, $email, $quotaGb, $expire, now()]);
    return get_sub((int)db()->lastInsertId());
}

function extend_sub(array $sub, float $quotaGb, int $days): array {
    $base = max(time(), strtotime((string)$sub['expire_at']) ?: time());
    $expire = date('Y-m-d H:i:s', $base + $days * 86400);
    $st = db()->prepare("UPDATE subs SET quota_gb=quota_gb+?, expire_at=?, status='active' WHERE id=?");
    $st->execute([$quotaGb, $expire, (int)$sub['id']]);
    return get_sub((int)$sub['id']);
}

function sub_remaining(array $sub): float {
    return max(0.0, (float)$sub['quota_gb'] - (float)$sub['used_gb']);
}

/**
 * 同步一个订阅池：拉取各服务器用量 → 更新 → 超额/到期则停号
 */
function pool_sync(int $subId, bool $enforce = true): array {
    $sub = get_sub($subId);
    if (!$sub) return ['ok' => false, 'msg' => '订阅不存在'];
    $accs = sub_accounts($subId);
    if (!$accs) return ['ok' => false, 'msg' => '无账号'];

    $quota = (float)$sub['quota_gb'];
    $items = [];
    $total = 0.0;
    $errors = [];

    $tzProbed = [];
    foreach ($accs as $a) {
        $srv = get_server((int)$a['server_id']);
        if (!$srv) { $errors[] = 'server#' . $a['server_id'] . ' 不存在'; continue; }
        try {
            $panel = HiddifyPanel::forServer($srv);
            $u = $panel->getUser((string)$a['uuid']);
            $sid = (int)$srv['id'];
            if (!isset($tzProbed[$sid])) {
                $tzProbed[$sid] = true;
                if (trim(setting('server_tz_h_' . $sid)) === ''
                    && (int)setting('tz_delta_at_' . $sid) < time() - 43200) {
                    $d = $panel->probeTimezoneDelta((string)$a['uuid']);
                    if ($d !== null) panel_tz_set($sid, $d);
                }
            }
            $usage = (float)($u['current_usage_GB'] ?? 0);
            $limit = (float)($u['usage_limit_GB'] ?? 0);
            $enabled = !empty($u['enable']);
            $seen = panel_time((string)($u['last_online'] ?? ''), $sid);
            // 全地区：面板值只取增量自己记账，面板重置/换号/加减服务器都不会让总量倒退
            $prev = (float)($a['panel_used'] ?? 0);
            $delta = $usage >= $prev ? ($usage - $prev) : $usage;
            $items[] = ['acc' => $a, 'srv' => $srv, 'panel' => $panel, 'usage' => $usage, 'limit' => $limit,
                        'enabled' => $enabled, 'seen' => $seen, 'delta' => $delta];
            $total += $delta;
        } catch (Throwable $e) {
            $errors[] = $a['region'] . '：' . $e->getMessage();
        }
    }

    $maxSeen = '';
    foreach ($items as $it) if ($it['seen'] > $maxSeen) $maxSeen = $it['seen'];
    $totalUsed = (float)$sub['used_gb'] + $total;
    if ($maxSeen !== '') {
        db()->prepare("UPDATE subs SET used_gb=?, last_sync=?, last_seen=? WHERE id=?")
            ->execute([$totalUsed, now(), $maxSeen, $subId]);
    } else {
        db()->prepare("UPDATE subs SET used_gb=?, last_sync=? WHERE id=?")->execute([$totalUsed, now(), $subId]);
    }
    foreach ($items as $it) {
        db()->prepare("UPDATE sub_accounts SET usage_gb=?, panel_used=?, last_sync=?, last_seen=? WHERE id=?")
            ->execute([$it['usage'], $it['usage'], now(), (string)$it['seen'], (int)$it['acc']['id']]);
    }
    // 全地区订单与订阅池共用同一份数据，订单行也要同步
    $tok = (string)($sub['token'] ?? '');
    if ($maxSeen !== '') {
        db()->prepare("UPDATE orders SET used_gb=?, last_seen=?, last_sync=? WHERE (sub_id=? OR (sub_token<>'' AND sub_token=?)) AND status IN ('paid','provisioned')")
            ->execute([$totalUsed, $maxSeen, now(), $subId, $tok]);
    } else {
        db()->prepare("UPDATE orders SET used_gb=?, last_sync=? WHERE (sub_id=? OR (sub_token<>'' AND sub_token=?)) AND status IN ('paid','provisioned')")
            ->execute([$totalUsed, now(), $subId, $tok]);
    }

    $expired = strtotime((string)$sub['expire_at']) < time();
    // 用累计已用（含历史），不能用本次同步的增量
    $exhausted = $quota > 0 && $totalUsed >= $quota;

    if (!$enforce) {
        return ['ok' => true, 'used' => $total, 'quota' => $quota, 'actions' => [], 'errors' => $errors];
    }

    $actions = [];
    if ($expired || $exhausted) {
        $status = $expired ? 'expired' : 'exhausted';
        foreach ($items as $it) {
            if ($it['enabled']) {
                try {
                    $it['panel']->updateUser((string)$it['acc']['uuid'], ['enable' => false]);
                    $actions[] = 'disable ' . $it['acc']['region'];
                } catch (Throwable $e) { $errors[] = 'disable ' . $it['acc']['region'] . '：' . $e->getMessage(); }
            }
        }
        db()->prepare("UPDATE subs SET status=? WHERE id=?")->execute([$status, $subId]);
    } else {
        // 每台账号的额度 = 总池 - 其他账号已用（等价于该账号还能用多少）
        $remainDays = max(1, (int)ceil((strtotime((string)$sub['expire_at']) - time()) / 86400));
        foreach ($items as $it) {
            $others = max(0.0, $totalUsed - $it['usage']);
            // quota=0 表示不限量：限额置 0（面板按不限处理）
            $allow = $quota > 0 ? max(0.01, $quota - $others) : 0.0;
            $need = $quota > 0
                ? (abs($allow - $it['limit']) > 1.0 || !$it['enabled'] || $it['limit'] <= 0)
                : (!$it['enabled'] || $it['limit'] > 0);
            if ($need) {
                try {
                    $it['panel']->updateUser((string)$it['acc']['uuid'], [
                        'usage_limit_GB' => round($allow, 2),
                        'package_days'   => $remainDays,
                        'start_date'     => date('Y-m-d'),
                        'enable'         => true,
                    ]);
                    $actions[] = 'sync ' . $it['acc']['region'] . ' limit=' . round($allow, 2);
                } catch (Throwable $e) { $errors[] = 'sync ' . $it['acc']['region'] . '：' . $e->getMessage(); }
            }
        }
        if ($sub['status'] !== 'active') db()->prepare("UPDATE subs SET status='active' WHERE id=?")->execute([$subId]);
    }

    return ['ok' => true, 'used' => $total, 'quota' => $quota, 'status' => $expired ? 'expired' : ($exhausted ? 'exhausted' : 'active'), 'actions' => $actions, 'errors' => $errors];
}

/** 全量同步（定时任务调用） */
function pool_sync_all(): array {
    $subs = db()->query("SELECT id FROM subs WHERE status IN ('active','exhausted')")->fetchAll();
    $out = [];
    foreach ($subs as $s) {
        $r = pool_sync((int)$s['id']);
        $out[] = ['id' => (int)$s['id']] + $r;
    }
    return $out;
}


/**
 * 同步单地区订单的已用流量（用于大屏统计）
 */
function sync_order_usage(array $o): array {
    $accs = order_accounts((int)$o['id']);
    $sum = 0.0; $err = []; $maxSeen = '';
    foreach ($accs as $a) {
        $srv = get_server((int)$a['server_id']);
        if (!$srv) continue;
        try {
            $panel = HiddifyPanel::forServer($srv);
            $u = $panel->getUser((string)$a['uuid']);
            $sid = (int)$srv['id'];
            if (trim(setting('server_tz_h_' . $sid)) === ''
                && (int)setting('tz_delta_at_' . $sid) < time() - 43200) {
                $d = $panel->probeTimezoneDelta((string)$a['uuid']);
                if ($d !== null) panel_tz_set($sid, $d);
            }
            $usage = (float)($u['current_usage_GB'] ?? 0);
            $seen = panel_time((string)($u['last_online'] ?? ''), $sid);
            $sum += $usage;
            if ($seen > $maxSeen) $maxSeen = $seen;
            db()->prepare("UPDATE order_accounts SET usage_gb=?, last_sync=?, last_seen=? WHERE id=?")
                ->execute([$usage, now(), $seen, (int)$a['id']]);
        } catch (Throwable $e) {
            $err[] = $e->getMessage();
        }
    }
    if ($maxSeen !== '') {
        db()->prepare("UPDATE orders SET used_gb=?, last_seen=?, last_sync=? WHERE id=?")->execute([$sum, $maxSeen, now(), (int)$o['id']]);
    } else {
        db()->prepare("UPDATE orders SET used_gb=?, last_sync=? WHERE id=?")->execute([$sum, now(), (int)$o['id']]);
    }
    return ['id' => (int)$o['id'], 'used' => $sum, 'seen' => $maxSeen, 'errors' => $err];
}

function sync_single_orders(int $limit = 300): array {
    $st = db()->prepare("SELECT * FROM orders WHERE status='provisioned' AND note='single' ORDER BY id DESC LIMIT ?");
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    $out = []; $done = [];
    foreach ($st->fetchAll() as $o) {
        $u = (string)($o['uuid'] ?? '');
        if ($u !== '' && isset($done[$u])) {
            // 同一面板账号（续费订单）只拉一次，结果写到这一行
            db()->prepare("UPDATE orders SET used_gb=?, last_sync=? WHERE id=?")
                ->execute([(float)$done[$u]['used'], now(), (int)$o['id']]);
            continue;
        }
        $r = sync_order_usage($o);
        if ($u !== '') $done[$u] = $r;
        $out[] = $r;
    }
    return $out;
}


/** 记录当日累计用量快照（用于趋势图）；同一天只升不降 */
function stats_snapshot(): void {
    $used = (float)db()->query("SELECT COALESCE(SUM(used_gb),0) FROM subs")->fetchColumn()
          + (float)db()->query("SELECT COALESCE(SUM(u),0) FROM (
                SELECT MAX(used_gb) AS u FROM orders WHERE status IN ('paid','provisioned') AND note='single' AND uuid<>'' GROUP BY uuid
                UNION ALL
                SELECT used_gb AS u FROM orders WHERE status IN ('paid','provisioned') AND note='single' AND uuid=''
            ) t")->fetchColumn();
    $cnt = (int)db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('paid','provisioned')")->fetchColumn();
    $rev = (float)db()->query("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status IN ('paid','provisioned')")->fetchColumn();
    $d = date('Y-m-d');
    $q = db()->quote($d);
    $prevUsed = db()->query("SELECT used_gb FROM stats_daily WHERE d < {$q} ORDER BY d DESC LIMIT 1")->fetchColumn();
    $start = $prevUsed === false ? 0.0 : (float)$prevUsed;
    if (db_driver() === 'mysql') {
        $st = db()->prepare("INSERT INTO stats_daily (d,start_gb,used_gb,orders,revenue) VALUES (?,?,?,?,?)
                             ON DUPLICATE KEY UPDATE used_gb=GREATEST(used_gb,VALUES(used_gb)), orders=VALUES(orders), revenue=VALUES(revenue)");
    } else {
        $st = db()->prepare("INSERT INTO stats_daily (d,start_gb,used_gb,orders,revenue) VALUES (?,?,?,?,?)
                             ON CONFLICT(d) DO UPDATE SET used_gb=MAX(used_gb,excluded.used_gb), orders=excluded.orders, revenue=excluded.revenue");
    }
    $st->execute([$d, $start, $used, $cnt, $rev]);
}

/* ================= 管理员编辑用户 ================= */

/** 校验并规范化编辑表单字段 */
function admin_user_input(array $in): array
{
    $email  = trim((string)($in['email'] ?? ''));
    $note   = trim((string)($in['note'] ?? ''));
    $limit  = max(0.0, (float)($in['limit_gb'] ?? 0));
    $expire = trim((string)($in['expire_at'] ?? ''));
    $on     = !empty($in['enabled']);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('邮箱格式不正确');
    }
    $ts = $expire !== '' ? strtotime($expire . ' 23:59:59') : false;
    if (!$ts) throw new InvalidArgumentException('到期时间不正确');
    return [$email, $note, $limit, date('Y-m-d H:i:s', $ts), $ts, $on];
}

/** 面板用户补丁：显示名 / 备注 / 额度 / 到期 / 启停 */
function panel_user_patch(string $email, string $note, float $limitGb, int $expireTs, bool $enabled): array
{
    $days = max(1, (int)ceil(($expireTs - time()) / 86400));
    $patch = [
        'usage_limit_GB' => $limitGb > 0 ? round($limitGb, 2) : 0,
        'package_days'   => $days,
        'start_date'     => date('Y-m-d'),
        'enable'         => $enabled,
        'comment'        => $note !== '' ? $note : ($email !== '' ? 'shop:' . $email : 'shop'),
    ];
    if ($email !== '') $patch['name'] = $email;
    return $patch;
}

/** 编辑订阅池（全地区）：写库 + 推送到每台面板 */
function admin_update_sub(int $subId, array $in): array
{
    $sub = get_sub($subId);
    if (!$sub) return ['ok' => false, 'msg' => '订阅不存在'];
    try {
        [$email, $note, $limit, $expireStr, $ts, $on] = admin_user_input($in);
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
    if ($ts <= time()) $on = false;   // 到期时间已过 → 直接停用
    $subStatus = $on ? 'active' : ($ts <= time() ? 'expired' : 'disabled');
    db()->prepare("UPDATE subs SET email=?, note=?, quota_gb=?, expire_at=?, status=? WHERE id=?")
        ->execute([$email, $note, $limit, $expireStr, $subStatus, $subId]);

    $total = (float)$sub['used_gb'];
    $ok = 0; $errs = [];
    foreach (sub_accounts($subId) as $a) {
        $srv = get_server((int)$a['server_id']);
        if (!$srv) { $errs[] = 'server#' . $a['server_id'] . ' 不存在'; continue; }
        try {
            $panel = HiddifyPanel::forServer($srv);
            // 每台账号额度 = 池配额 - 其他账号已用（与 pool_sync 口径一致）
            $other = max(0.0, $total - (float)$a['usage_gb']);
            $allow = $limit > 0 ? max(0.01, $limit - $other) : 0;
            $panel->updateUser((string)$a['uuid'], panel_user_patch($email, $note, $allow, $ts, $on));
            $ok++;
        } catch (Throwable $e) {
            $errs[] = ($a['region'] ?: ('server#' . $a['server_id'])) . '：' . $e->getMessage();
        }
    }
    return ['ok' => true, 'updated' => $ok, 'errors' => $errs];
}

/** 编辑单地区订单：写库 + 推送到面板 */
function admin_update_order(array $o, array $in): array
{
    try {
        [$email, $note, $limit, $expireStr, $ts, $on] = admin_user_input($in);
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'msg' => $e->getMessage()];
    }
    if ($ts <= time()) $on = false;   // 到期时间已过 → 直接停用
    $paidTs = strtotime((string)$o['paid_at']) ?: time();
    $days = max(1, (int)ceil(($ts - $paidTs) / 86400));
    db()->prepare("UPDATE orders SET email=?, admin_note=?, traffic_gb=?, expire_at=?, panel_enable=?, days=? WHERE id=?")
        ->execute([$email, $note, $limit, $expireStr, $on ? 1 : 0, $days, (int)$o['id']]);

    $ok = 0; $errs = [];
    foreach (order_accounts((int)$o['id']) as $a) {
        $srv = get_server((int)$a['server_id']);
        if (!$srv) { $errs[] = 'server#' . $a['server_id'] . ' 不存在'; continue; }
        try {
            $panel = HiddifyPanel::forServer($srv);
            $panel->updateUser((string)$a['uuid'], panel_user_patch($email, $note, $limit, $ts, $on));
            $ok++;
        } catch (Throwable $e) {
            $errs[] = ($a['region'] ?: ('server#' . $a['server_id'])) . '：' . $e->getMessage();
        }
    }
    return ['ok' => true, 'updated' => $ok, 'errors' => $errs];
}
