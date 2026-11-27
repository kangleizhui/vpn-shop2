<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mailer.php';

/**
 * 到期 / 流量提醒
 * - 到期前 3 天内提醒一次
 * - 已过期提醒一次
 * - 流量 80% / 95% 各提醒一次（按 到期时间+额度 去重，续费后自动重置）
 */
function notify_run(bool $dry = false): array {
    $c = smtp_settings();
    $canSend = $c['enabled'] && smtp_configured();
    $now = time();
    $siteUrl = rtrim(cfg('site_url'), '/');
    $out = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [], 'preview' => []];

    $jobs = [];

    // 共享流量池
    foreach (db()->query("SELECT * FROM subs ORDER BY id")->fetchAll() as $s) {
        $out['checked']++;
        $exp = strtotime((string)$s['expire_at']) ?: 0;
        $days = $exp > 0 ? (int)floor(($exp - $now) / 86400) : 9999;
        $quota = (float)$s['quota_gb'];
        $used  = (float)$s['used_gb'];
        $pct   = $quota > 0 ? $used / $quota * 100 : 0;
        $base  = 'sub:' . $s['id'] . ':' . $s['expire_at'];
        $token = (string)$s['token'];

        if ($exp > 0 && $days >= 0 && $days <= 3) {
            $jobs[] = ['type' => 'exp3', 'ref' => $base, 'email' => $s['email'], 'token' => $token,
                'title' => '你的订阅将在 ' . max(0, $days) . ' 天后到期',
                'intro' => '为了避免突然断网，建议现在续费。续费后有效期与流量会自动叠加。',
                'rows' => ['到期时间' => $s['expire_at'], '剩余' => max(0, $days) . ' 天',
                           '已用 / 总量' => number_format($used, 2) . ' / ' . number_format($quota, 0) . ' GB']];
        } elseif ($exp > 0 && $days < 0) {
            $jobs[] = ['type' => 'exp0', 'ref' => $base . ':dead', 'email' => $s['email'], 'token' => $token,
                'title' => '你的订阅已到期',
                'intro' => '节点已暂停使用。续费后会自动恢复，原有用量记录保留。',
                'rows' => ['到期时间' => $s['expire_at'], '状态' => '已到期',
                           '已用 / 总量' => number_format($used, 2) . ' / ' . number_format($quota, 0) . ' GB']];
        }

        if ($quota > 0 && $days >= 0) {
            if ($pct >= 95) {
                $jobs[] = ['type' => 'use95', 'ref' => $base . ':' . $quota, 'email' => $s['email'], 'token' => $token,
                    'title' => '流量即将用尽（已用 ' . round($pct) . '%）',
                    'intro' => '当前流量已使用 ' . round($pct) . '%，用尽后节点会自动停用。建议提前续费。',
                    'rows' => ['已用 / 总量' => number_format($used, 2) . ' / ' . number_format($quota, 0) . ' GB',
                               '剩余流量' => number_format(max(0, $quota - $used), 2) . ' GB',
                               '到期时间' => $s['expire_at']]];
            } elseif ($pct >= 80) {
                $jobs[] = ['type' => 'use80', 'ref' => $base . ':' . $quota, 'email' => $s['email'], 'token' => $token,
                    'title' => '流量已使用 ' . round($pct) . '%',
                    'intro' => '提醒一下，你的流量即将过半程以后，注意剩余额度。',
                    'rows' => ['已用 / 总量' => number_format($used, 2) . ' / ' . number_format($quota, 0) . ' GB',
                               '剩余流量' => number_format(max(0, $quota - $used), 2) . ' GB',
                               '到期时间' => $s['expire_at']]];
            }
        }
    }

    // 单地区订单
    $orders = db()->query("SELECT * FROM orders WHERE status='provisioned' AND (sub_id IS NULL OR sub_id=0) AND email LIKE '%@%'")->fetchAll();
    foreach ($orders as $o) {
        $out['checked']++;
        if (empty($o['paid_at'])) continue;
        $exp  = trim((string)($o['expire_at'] ?? '')) !== ''
            ? (strtotime((string)$o['expire_at']) ?: 0)
            : ((strtotime((string)$o['paid_at']) ?: 0) + (int)$o['days'] * 86400);
        $days = (int)floor(($exp - $now) / 86400);
        $quota = (float)$o['traffic_gb'];
        $used  = (float)$o['used_gb'];
        $pct   = $quota > 0 ? $used / $quota * 100 : 0;
        $base  = 'ord:' . $o['id'] . ':' . $o['paid_at'] . ':' . $o['days'] . ':' . $o['traffic_gb'];
        $token = (string)($o['sub_token'] ?? '');
        $rows  = ['套餐' => $o['plan_name'], '到期时间' => date('Y-m-d H:i:s', $exp),
                  '已用 / 总量' => number_format($used, 2) . ' / ' . number_format($quota, 0) . ' GB'];

        if ($days >= 0 && $days <= 3) {
            $jobs[] = ['type' => 'exp3', 'ref' => $base, 'email' => $o['email'], 'token' => $token,
                'title' => '你的套餐将在 ' . max(0, $days) . ' 天后到期',
                'intro' => '为了避免突然断网，建议现在续费。同邮箱续费会自动叠加，不会新建账号。',
                'rows' => $rows + ['剩余' => max(0, $days) . ' 天']];
        } elseif ($days < 0) {
            $jobs[] = ['type' => 'exp0', 'ref' => $base . ':dead', 'email' => $o['email'], 'token' => $token,
                'title' => '你的套餐已到期',
                'intro' => '节点已暂停使用，续费后自动恢复。',
                'rows' => $rows + ['状态' => '已到期']];
        }
        if ($quota > 0 && $days >= 0) {
            if ($pct >= 95) {
                $jobs[] = ['type' => 'use95', 'ref' => $base . ':' . $quota, 'email' => $o['email'], 'token' => $token,
                    'title' => '流量即将用尽（已用 ' . round($pct) . '%）',
                    'intro' => '当前流量已使用 ' . round($pct) . '%，用尽后会自动停用。',
                    'rows' => $rows];
            } elseif ($pct >= 80) {
                $jobs[] = ['type' => 'use80', 'ref' => $base . ':' . $quota, 'email' => $o['email'], 'token' => $token,
                    'title' => '流量已使用 ' . round($pct) . '%',
                    'intro' => '提醒一下，你的流量已超过 80%。',
                    'rows' => $rows];
            }
        }
    }

    foreach ($jobs as $j) {
        if (notice_done($j['type'], $j['ref'])) { $out['skipped']++; continue; }
        $tk = (string)($j['token'] ?? '');
        $url = $tk !== '' ? $siteUrl . '/s/' . $tk : $siteUrl . '/query';
        if ($dry) { $out['preview'][] = ['type' => $j['type'], 'email' => $j['email'], 'title' => $j['title'], 'url' => $url]; continue; }
        if (!$canSend) { $out['skipped']++; continue; }

        $html = notify_mail_html([
            'title' => $j['title'], 'intro' => $j['intro'], 'rows' => $j['rows'],
            'url' => $url, 'foot' => '客服：' . cfg('contact', '') ,
        ]);
        $r = smtp_send((string)$j['email'], cfg('site_name', '云商城') . ' - ' . $j['title'], $html);
        if ($r['ok']) {
            notice_mark($j['type'], $j['ref']);
            $out['sent']++;
        } else {
            $out['failed']++;
            if (count($out['errors']) < 5) $out['errors'][] = $j['email'] . ': ' . $r['msg'];
        }
    }
    return $out;
}
