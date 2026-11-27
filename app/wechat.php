<?php
declare(strict_types=1);

/* ================= 微信支付官方 API v3（三种场景各自独立配置） ================= */

function wechat_channels(): array { return ['wechat_native', 'wechat_h5', 'wechat_jsapi']; }

function wechat_conf(string $ch): array {
    $p = $ch . '_';
    return [
        'appid'       => trim(setting($p . 'appid')),
        'mchid'       => trim(setting($p . 'mchid')),
        'serial'      => trim(setting($p . 'serial')),
        'private_key' => secret_decrypt(setting($p . 'private_key')),
        'apiv3_key'   => secret_decrypt(setting($p . 'apiv3_key')),
        'pubkey_id'   => trim(setting($p . 'pubkey_id')),
        'pubkey'      => secret_decrypt(setting($p . 'pubkey')),
    ];
}
function wechat_configured(?string $ch = null): bool {
    if ($ch === null) {
        foreach (wechat_channels() as $c) if (wechat_configured($c)) return true;
        return false;
    }
    $c = wechat_conf($ch);
    if ($c['appid'] === '' || $c['mchid'] === '' || $c['serial'] === ''
        || $c['private_key'] === '' || strlen($c['apiv3_key']) !== 32) return false;
    if ($ch === 'wechat_jsapi' && secret_decrypt(setting('wechat_jsapi_secret')) === '') return false;
    return true;
}
function wechat_priv_pem(string $ch): string { return pem_wrap(wechat_conf($ch)['private_key'], 'PRIVATE KEY'); }

function wechat_header(string $name): string {
    $k = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$k])) return (string)$_SERVER[$k];
    if (function_exists('getallheaders')) {
        $h = array_change_key_case(getallheaders(), CASE_LOWER);
        return (string)($h[strtolower($name)] ?? '');
    }
    return '';
}

function wechat_sign(string $method, string $pathWithQuery, string $body, string $ch): array {
    $ts = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $msg = $method . "\n" . $pathWithQuery . "\n" . $ts . "\n" . $nonce . "\n" . $body . "\n";
    $key = openssl_pkey_get_private(wechat_priv_pem($ch));
    if (!$key) throw new RuntimeException('商户私钥格式错误');
    openssl_sign($msg, $sig, $key, OPENSSL_ALGO_SHA256);
    $c = wechat_conf($ch);
    $auth = sprintf('WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",signature="%s",timestamp="%s",serial_no="%s"',
        $c['mchid'], $nonce, base64_encode($sig), $ts, $c['serial']);
    return ['Authorization: ' . $auth, 'Accept: application/json', 'Content-Type: application/json'];
}

/** Native 扫码 */
function wechat_native(array $o): array {
    $ch = 'wechat_native';
    $c = wechat_conf($ch);
    $body = json_encode([
        'appid'       => $c['appid'],
        'mchid'       => $c['mchid'],
        'description' => '商品购物',
        'out_trade_no'=> (string)$o['order_no'],
        'notify_url'  => site_base() . '/notify/wechat',
        'amount'      => ['total' => (int)round((float)$o['amount'] * 100), 'currency' => 'CNY'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try { $h = wechat_sign('POST', '/v3/pay/transactions/native', $body, $ch); }
    catch (Throwable $e) { return ['ok' => false, 'msg' => $e->getMessage()]; }
    $r = http_json_post('https://api.mch.weixin.qq.com/v3/pay/transactions/native', $body, $h);
    $j = json_decode($r['body'], true);
    if ($r['code'] >= 200 && $r['code'] < 300 && !empty($j['code_url'])) return ['ok' => true, 'qr' => (string)$j['code_url']];
    return ['ok' => false, 'msg' => (string)($j['message'] ?? ('HTTP ' . $r['code']))];
}

/** H5 支付 */
function wechat_h5(array $o, string $ip): array {
    $ch = 'wechat_h5';
    $c = wechat_conf($ch);
    $body = json_encode([
        'appid'       => $c['appid'],
        'mchid'       => $c['mchid'],
        'description' => '商品购物',
        'out_trade_no'=> (string)$o['order_no'],
        'notify_url'  => site_base() . '/notify/wechat',
        'amount'      => ['total' => (int)round((float)$o['amount'] * 100), 'currency' => 'CNY'],
        'scene_info'  => [
            'payer_client_ip' => $ip !== '' ? $ip : '127.0.0.1',
            'h5_info' => [
                'type'     => 'Wap',
                'wap_url'  => trim(setting('wechat_h5_domain')) ?: site_base(),
                'wap_name' => (string)cfg('site_name', '云商城'),
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try { $h = wechat_sign('POST', '/v3/pay/transactions/h5', $body, $ch); }
    catch (Throwable $e) { return ['ok' => false, 'msg' => $e->getMessage()]; }
    $r = http_json_post('https://api.mch.weixin.qq.com/v3/pay/transactions/h5', $body, $h);
    $j = json_decode($r['body'], true);
    if ($r['code'] >= 200 && $r['code'] < 300 && !empty($j['h5_url'])) return ['ok' => true, 'url' => (string)$j['h5_url']];
    return ['ok' => false, 'msg' => (string)($j['message'] ?? ('HTTP ' . $r['code']))];
}

/** JSAPI 下单（公众号内，需要 openid） */
function wechat_jsapi_order(array $o, string $openid): array {
    $ch = 'wechat_jsapi';
    $c = wechat_conf($ch);
    $body = json_encode([
        'appid'       => $c['appid'],
        'mchid'       => $c['mchid'],
        'description' => '商品购物',
        'out_trade_no'=> (string)$o['order_no'],
        'notify_url'  => site_base() . '/notify/wechat',
        'amount'      => ['total' => (int)round((float)$o['amount'] * 100), 'currency' => 'CNY'],
        'payer'       => ['openid' => $openid],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    try { $h = wechat_sign('POST', '/v3/pay/transactions/jsapi', $body, $ch); }
    catch (Throwable $e) { return ['ok' => false, 'msg' => $e->getMessage()]; }
    $r = http_json_post('https://api.mch.weixin.qq.com/v3/pay/transactions/jsapi', $body, $h);
    $j = json_decode($r['body'], true);
    if ($r['code'] >= 200 && $r['code'] < 300 && !empty($j['prepay_id'])) return ['ok' => true, 'prepay_id' => (string)$j['prepay_id']];
    return ['ok' => false, 'msg' => (string)($j['message'] ?? ('HTTP ' . $r['code']))];
}

/** 前端拉起支付参数 */
function wechat_jsapi_params(string $prepayId): array {
    $ch = 'wechat_jsapi';
    $appid = wechat_conf($ch)['appid'];
    $ts = (string)time();
    $nonce = bin2hex(random_bytes(16));
    $pkg = 'prepay_id=' . $prepayId;
    $msg = $appid . "\n" . $ts . "\n" . $nonce . "\n" . $pkg . "\n";
    $key = openssl_pkey_get_private(wechat_priv_pem($ch));
    if (!$key) throw new RuntimeException('商户私钥格式错误');
    openssl_sign($msg, $sig, $key, OPENSSL_ALGO_SHA256);
    return [
        'appId'     => $appid,
        'timeStamp' => $ts,
        'nonceStr'  => $nonce,
        'package'   => $pkg,
        'signType'  => 'RSA',
        'paySign'   => base64_encode($sig),
    ];
}

/** 查询订单 */
function wechat_query(string $outNo, string $ch = 'wechat_native'): array {
    if (!wechat_configured($ch)) return ['ok' => false, 'state' => ''];
    $c = wechat_conf($ch);
    $path = '/v3/pay/transactions/out-trade-no/' . rawurlencode($outNo) . '?mchid=' . rawurlencode($c['mchid']);
    try { $h = wechat_sign('GET', $path, '', $ch); } catch (Throwable $e) { return ['ok' => false, 'state' => '']; }

    $cl = curl_init('https://api.mch.weixin.qq.com' . $path);
    curl_setopt_array($cl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $h]);
    $body = curl_exec($cl);
    $code = (int)curl_getinfo($cl, CURLINFO_HTTP_CODE);
    curl_close($cl);
    $j = json_decode((string)$body, true);
    return [
        'ok'     => $code === 200,
        'state'  => (string)($j['trade_state'] ?? ''),
        'trade'  => (string)($j['transaction_id'] ?? ''),
        'amount' => isset($j['amount']['total']) ? ((int)$j['amount']['total']) / 100 : 0,
    ];
}

/** AES-256-GCM 解密 */
function wechat_decrypt(array $r, string $ch): string {
    $key = wechat_conf($ch)['apiv3_key'];
    $raw = base64_decode((string)($r['ciphertext'] ?? ''), true);
    if ($raw === false || strlen($raw) < 17) return '';
    $tag = substr($raw, -16);
    $ct  = substr($raw, 0, -16);
    $ad  = (string)($r['associated_data'] ?? '');
    $nonce = (string)($r['nonce'] ?? '');
    $out = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $ad);
    return is_string($out) ? $out : '';
}

/** 平台证书（按渠道缓存，因为可能是不同商户号） */
function wechat_certs(string $ch, bool $reload = false): array {
    $file = DATA_DIR . '/wechat_certs_' . $ch . '.json';
    if (!$reload && is_file($file)) {
        $c = json_decode((string)file_get_contents($file), true);
        if (is_array($c) && ($c['exp'] ?? 0) > time()) return $c['map'] ?? [];
    }
    try { $h = wechat_sign('GET', '/v3/certificates', '', $ch); } catch (Throwable $e) { return []; }
    $cl = curl_init('https://api.mch.weixin.qq.com/v3/certificates');
    curl_setopt_array($cl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $h]);
    $body = curl_exec($cl);
    curl_close($cl);
    $j = json_decode((string)$body, true);
    $map = [];
    foreach (($j['data'] ?? []) as $d) {
        $pem = wechat_decrypt($d['encrypt_certificate'] ?? [], $ch);
        if ($pem !== '') $map[(string)$d['serial_no']] = $pem;
    }
    if ($map) {
        @file_put_contents($file, json_encode(['exp' => time() + 12 * 3600, 'map' => $map]));
        @chmod($file, 0600);
    }
    return $map;
}

/** 取用于验签的平台公钥：优先微信支付公钥（新版商户），否则平台证书 */
function wechat_platform_key(string $ch, string $serial): string {
    $c = wechat_conf($ch);
    if ($c['pubkey_id'] !== '' && $serial === $c['pubkey_id'] && $c['pubkey'] !== '') {
        return pem_wrap($c['pubkey'], 'PUBLIC KEY');
    }
    $certs = wechat_certs($ch);
    if (!isset($certs[$serial])) $certs = wechat_certs($ch, true);
    return (string)($certs[$serial] ?? '');
}

/** 回调：逐个渠道验签 + 解密 */
function wechat_notify(string $body): ?array {
    $ts     = wechat_header('Wechatpay-Timestamp');
    $nonce  = wechat_header('Wechatpay-Nonce');
    $sigB64 = wechat_header('Wechatpay-Signature');
    $serial = wechat_header('Wechatpay-Serial');
    if ($ts === '' || $nonce === '' || $sigB64 === '') return null;
    if (abs(time() - (int)$ts) > 300) { slog('warn', 'wechat notify timestamp skew'); return null; }

    $msg = $ts . "\n" . $nonce . "\n" . $body . "\n";
    foreach (wechat_channels() as $ch) {
        if (!wechat_configured($ch)) continue;
        $pem = wechat_platform_key($ch, $serial);
        if ($pem === '') continue;
        if (openssl_verify($msg, base64_decode($sigB64, true) ?: '', $pem, OPENSSL_ALGO_SHA256) !== 1) continue;

        $j = json_decode($body, true);
        if (!is_array($j)) return null;
        if (($j['event_type'] ?? '') !== 'TRANSACTION.SUCCESS') return $j;
        $plain = wechat_decrypt($j['resource'] ?? [], $ch);
        $d = json_decode($plain, true);
        if (is_array($d)) $d['_channel'] = $ch;
        return is_array($d) ? $d : null;
    }
    slog('warn', 'wechat notify verify fail');
    return null;
}

/** 公众号授权（snsapi_base） */
function wechat_oauth_url(string $appid, string $redirect): string {
    return 'https://open.weixin.qq.com/connect/oauth2/authorize?appid=' . rawurlencode($appid)
        . '&redirect_uri=' . rawurlencode($redirect)
        . '&response_type=code&scope=snsapi_base&state=pay#wechat_redirect';
}
function wechat_openid(string $appid, string $secret, string $code): array {
    $url = 'https://api.weixin.qq.com/sns/oauth2/access_token?appid=' . rawurlencode($appid)
        . '&secret=' . rawurlencode($secret) . '&code=' . rawurlencode($code) . '&grant_type=authorization_code';
    $j = json_decode(http_get($url), true);
    if (!empty($j['openid'])) return ['ok' => true, 'openid' => (string)$j['openid']];
    return ['ok' => false, 'msg' => (string)($j['errmsg'] ?? '获取 openid 失败')];
}

function wechat_ack(bool $ok, string $msg = ''): void {
    http_response_code($ok ? 200 : 400);
    header('Content-Type: application/json');
    echo json_encode($ok ? ['code' => 'SUCCESS', 'message' => '成功'] : ['code' => 'FAIL', 'message' => $msg]);
    exit;
}
