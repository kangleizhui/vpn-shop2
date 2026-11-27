<?php
declare(strict_types=1);

/* ================= 支付宝官方（三种方式各自独立配置） ================= */

function alipay_channels(): array { return ['alipay_page', 'alipay_wap', 'alipay_qr']; }

function alipay_conf(string $ch): array {
    $p = $ch . '_';
    return [
        'app_id'      => trim(setting($p . 'app_id')),
        'private_key' => secret_decrypt(setting($p . 'private_key')),
        'public_key'  => secret_decrypt(setting($p . 'public_key')),
        'gateway'     => trim(setting($p . 'gateway')) ?: 'https://openapi.alipay.com/gateway.do',
    ];
}
function alipay_configured(?string $ch = null): bool {
    if ($ch === null) {
        foreach (alipay_channels() as $c) if (alipay_configured($c)) return true;
        return false;
    }
    $c = alipay_conf($ch);
    return $c['app_id'] !== '' && $c['private_key'] !== '' && $c['public_key'] !== '';
}

/** 把用户粘贴的裸 base64 补成 PEM */
function pem_wrap(string $key, string $type): string {
    $k = trim($key);
    if ($k === '') return '';
    if (str_contains($k, '-----BEGIN')) return $k;
    return "-----BEGIN {$type}-----\n" . chunk_split(preg_replace('/\s+/', '', $k), 64, "\n") . "-----END {$type}-----\n";
}
function alipay_priv_pem(string $ch): string {
    $raw = alipay_conf($ch)['private_key'];
    $pem = pem_wrap($raw, 'PRIVATE KEY');           // PKCS#8
    if (@openssl_pkey_get_private($pem)) return $pem;
    return pem_wrap($raw, 'RSA PRIVATE KEY');       // PKCS#1（支付宝密钥工具导出多为这种）
}
function alipay_pub_pem(string $ch): string {
    $raw = alipay_conf($ch)['public_key'];
    $pem = pem_wrap($raw, 'PUBLIC KEY');
    if (@openssl_pkey_get_public($pem)) return $pem;
    return pem_wrap($raw, 'RSA PUBLIC KEY');
}

/** 请求签名串：只去掉 sign 和空值（支付宝网关校验请求签名时 sign_type 参与签名） */
function alipay_sign_content(array $p): string {
    unset($p['sign']);
    ksort($p);
    $pairs = [];
    foreach ($p as $k => $v) {
        if ($v === '' || $v === null || is_array($v)) continue;
        $pairs[] = $k . '=' . $v;
    }
    return implode('&', $pairs);
}
function alipay_sign(array $p, string $ch): string {
    $key = openssl_pkey_get_private(alipay_priv_pem($ch));
    if (!$key) throw new RuntimeException('应用私钥格式错误');
    openssl_sign(alipay_sign_content($p), $sig, $key, OPENSSL_ALGO_SHA256);
    return base64_encode($sig);
}
function alipay_verify(array $p, string $ch): bool {
    if (empty($p['sign'])) return false;
    $sign = base64_decode((string)$p['sign'], true);
    if ($sign === false) return false;
    $key = openssl_pkey_get_public(alipay_pub_pem($ch));
    if (!$key) return false;
    $q = $p; unset($q['sign_type']);          // 异步通知验签：sign_type 不参与
    return openssl_verify(alipay_sign_content($q), $sign, $key, OPENSSL_ALGO_SHA256) === 1;
}

function site_base(): string {
    $b = rtrim(cfg('site_url'), '/');
    if ($b === '') {
        $b = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    }
    return $b;
}

/** 公共参数 */
function alipay_params(string $method, array $biz, array $extra, string $ch): array {
    $base = [
        'app_id'     => alipay_conf($ch)['app_id'],
        'method'     => $method,
        'format'     => 'JSON',
        'charset'    => 'utf-8',
        'sign_type'  => 'RSA2',
        'timestamp'  => date('Y-m-d H:i:s'),
        'version'    => '1.0',
        'notify_url' => site_base() . '/notify/alipay',
    ];
    $p = array_merge($base, $extra);
    if ($biz) $p['biz_content'] = json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $p['sign'] = alipay_sign($p, $ch);
    return $p;
}

/** 电脑网站支付 */
function alipay_page_url(array $o): string {
    $p = alipay_params('alipay.trade.page.pay', [
        'out_trade_no' => $o['order_no'],
        'total_amount' => number_format((float)$o['amount'], 2, '.', ''),
        'subject'      => '商品购物',
        'product_code' => 'FAST_INSTANT_TRADE_PAY',
    ], ['return_url' => site_base() . '/return'], 'alipay_page');
    return alipay_conf('alipay_page')['gateway'] . '?' . http_build_query($p);
}

/** 手机网站支付 */
function alipay_wap_url(array $o): string {
    $p = alipay_params('alipay.trade.wap.pay', [
        'out_trade_no' => $o['order_no'],
        'total_amount' => number_format((float)$o['amount'], 2, '.', ''),
        'subject'      => '商品购物',
        'product_code' => 'QUICK_WAP_WAY',
        'quit_url'     => site_base() . '/order/' . $o['order_no'],
    ], ['return_url' => site_base() . '/return'], 'alipay_wap');
    return alipay_conf('alipay_wap')['gateway'] . '?' . http_build_query($p);
}

/** 当面付：预下单拿二维码 */
function alipay_precreate(array $o): array {
    $biz = [
        'out_trade_no' => $o['order_no'],
        'total_amount' => number_format((float)$o['amount'], 2, '.', ''),
        'subject'      => '商品购物',
    ];
    $store = trim(setting('alipay_qr_store_id'));
    if ($store !== '') $biz['store_id'] = $store;

    $p = alipay_params('alipay.trade.precreate', $biz, [], 'alipay_qr');
    $raw = http_form_post(alipay_conf('alipay_qr')['gateway'], $p);
    $j = json_decode($raw, true);
    $r = $j['alipay_trade_precreate_response'] ?? null;
    if (!is_array($r)) return ['ok' => false, 'msg' => '支付宝返回格式异常'];
    if (($r['code'] ?? '') !== '10000' || empty($r['qr_code'])) {
        return ['ok' => false, 'msg' => (string)($r['sub_msg'] ?? $r['msg'] ?? '下单失败')];
    }
    return ['ok' => true, 'qr' => (string)$r['qr_code']];
}

/** 查询订单 */
function alipay_query(string $outNo, string $ch = 'alipay_qr'): array {
    if (!alipay_configured($ch)) return ['ok' => false, 'state' => '', 'trade' => '', 'amount' => ''];
    $p = alipay_params('alipay.trade.query', ['out_trade_no' => $outNo], [], $ch);
    $raw = http_form_post(alipay_conf($ch)['gateway'], $p);
    $j = json_decode($raw, true);
    $r = $j['alipay_trade_query_response'] ?? [];
    return [
        'ok'     => ($r['code'] ?? '') === '10000',
        'state'  => (string)($r['trade_status'] ?? ''),
        'trade'  => (string)($r['trade_no'] ?? ''),
        'amount' => (string)($r['total_amount'] ?? ''),
    ];
}

/** 异步通知：三种方式共用一个回调地址，逐个密钥验签 */
function alipay_notify(): bool {
    $p = $_POST;
    $hit = '';
    foreach (alipay_channels() as $ch) {
        if (!alipay_configured($ch)) continue;
        if (alipay_verify($p, $ch)) { $hit = $ch; break; }
    }
    if ($hit === '') { slog('warn', 'alipay notify sign fail'); return false; }

    $no = (string)($p['out_trade_no'] ?? '');
    $o = get_order($no);
    if (!$o) return false;
    if ((float)($p['total_amount'] ?? 0) + 0.001 < (float)$o['amount']) {
        slog('warn', 'alipay amount mismatch ' . $no);
        return false;
    }
    if (in_array((string)($p['trade_status'] ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
        mark_paid($no, (string)($p['trade_no'] ?? ''), $hit);
    }
    return true;
}

/* ---------- HTTP ---------- */
function http_form_post(string $url, array $data): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded;charset=utf-8'],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return is_string($r) ? $r : '';
}
function http_json_post(string $url, string $body, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    $r = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($r) ? $r : ''];
}
function http_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = curl_exec($ch);
    curl_close($ch);
    return is_string($r) ? $r : '';
}
