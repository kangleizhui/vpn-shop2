<?php
declare(strict_types=1);

class PanelError extends RuntimeException {}

/**
 * Hiddify 面板客户端
 * 只需要两样东西：管理员地址（含 UUID）+ 密码
 *   例：https://panel.example.com/你的安全路径/你的UUID
 * 登录后自动从 settings 读取 proxy_path_client 生成订阅地址
 */
class HiddifyPanel
{
    private string $base;
    private string $adminPath;
    private string $username;
    private string $password;
    private string $cookieFile;
    private array $server;

    public function __construct(array $server)
    {
        $this->server = $server;
        [$base, $path, $uuid] = self::parseAdminUrl((string)$server['admin_url']);
        if ($base === '' || $path === '' || $uuid === '') {
            throw new PanelError('管理员地址格式不正确，应形如：https://域名/后台路径/UUID');
        }
        $this->base = $base;
        $this->adminPath = $path;
        $this->username = $uuid;
        $this->password = secret_decrypt((string)$server['password']);
        $this->cookieFile = DATA_DIR . '/panel_' . (int)($server['id'] ?? 0) . '.cookie';
    }

    public static function forServer(array $server): self { return new self($server); }

    /** 解析 https://host/路径/UUID */
    public static function parseAdminUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') return ['', '', ''];
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return ['', '', ''];
        $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $segs = array_values(array_filter(explode('/', trim($p['path'] ?? '', '/'))));
        if (count($segs) < 2) return ['', '', ''];
        $uuid = array_pop($segs);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            return ['', '', ''];
        }
        return [$base, implode('/', $segs), $uuid];
    }

    private function curl(string $url, array $opt = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VPNShop/1.1)',
        ] + $opt);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new PanelError('网络错误：' . $err);
        return ['code' => $code, 'body' => (string)$body];
    }

    private function apiUrl(string $path): string
    {
        return $this->base . '/' . $this->adminPath . '/api/v2/admin/' . ltrim($path, '/');
    }

    public function login(): void
    {
        if ($this->password === '') throw new PanelError('未配置管理员密码');

        $probe = $this->curl($this->apiUrl('me/'));
        if ($probe['code'] === 200 && str_contains($probe['body'], '"mode"')) return;

        $loginUrl = $this->base . '/' . $this->adminPath . '/';
        $page = $this->curl($loginUrl);
        if (!preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $page['body'], $m)) {
            throw new PanelError('无法获取登录页（请检查管理员地址）');
        }
        $this->curl($loginUrl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'csrf_token' => $m[1],
                'username'   => $this->username,
                'password'   => $this->password,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $check = $this->curl($this->apiUrl('me/'));
        if ($check['code'] !== 200) {
            throw new PanelError('登录失败（' . $check['code'] . '）：密码错误或地址失效');
        }
        slog('info', 'panel login ok server#' . (int)($this->server['id'] ?? 0));
    }

    private function api(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Accept: application/json'];
        $opts = [CURLOPT_CUSTOMREQUEST => strtoupper($method)];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        $r = $this->curl($this->apiUrl($path), $opts);
        if ($r['code'] === 401) { $this->login(); $r = $this->curl($this->apiUrl($path), $opts); }
        if ($r['code'] < 200 || $r['code'] >= 300) {
            throw new PanelError('API 失败 [' . $method . ' ' . $path . '] ' . $r['code'] . '：' . mb_substr($r['body'], 0, 300));
        }
        $json = json_decode($r['body'], true);
        if (!is_array($json)) throw new PanelError('面板返回格式异常');
        return $json;
    }

    /** 自动发现订阅路径 proxy_path_client */
    public function discoverSubBase(): string
    {
        $this->login();
        $d = $this->api('GET', 'settings/');
        $path = '';
        foreach (($d['categories'] ?? []) as $cat) {
            foreach (($cat['fields'] ?? []) as $f) {
                if (($f['key'] ?? '') === 'proxy_path_client') { $path = (string)($f['value'] ?? ''); break 2; }
            }
        }
        if ($path === '') throw new PanelError('未能读取订阅路径（proxy_path_client）');
        return $this->base . '/' . trim($path, '/');
    }

    public function settingsMap(): array
    {
        $this->login();
        $d = $this->api('GET', 'settings/');
        $out = [];
        foreach (($d['categories'] ?? []) as $cat) {
            foreach (($cat['fields'] ?? []) as $f) {
                $out[(string)($f['key'] ?? '')] = $f['value'] ?? null;
            }
        }
        return $out;
    }

    public function createUser(string $name, int $days, float $trafficGb, string $email = ''): array
    {
        $this->login();
        $payload = [
            'name'         => $name,
            'package_days' => $days,
            'enable'       => true,
            'mode'         => 'no_reset',
            'start_date'   => date('Y-m-d'),
            'comment'      => $email !== '' ? ('shop:' . $email) : 'shop',
        ];
        if ($trafficGb > 0) $payload['usage_limit_GB'] = (float)$trafficGb;
        $u = $this->api('POST', 'user/', $payload);
        if (empty($u['uuid'])) throw new PanelError('创建用户失败：未返回 UUID');
        return $u;
    }

    public function getUser(string $uuid): array { $this->login(); return $this->api('GET', 'user/' . rawurlencode($uuid) . '/'); }
    public function updateUser(string $uuid, array $patch): array { $this->login(); return $this->api('PATCH', 'user/' . rawurlencode($uuid) . '/', $patch); }
    /** 探测面板时区差：改一次同值 comment，读回 last_modified_time（面板本地时间） */
    public function probeTimezoneDelta(string $uuid): ?int
    {
        try {
            $this->login();
            $u = $this->getUser($uuid);
            $r = $this->updateUser($uuid, ['comment' => (string)($u['comment'] ?? '')]);
            $lm = (string)($r['last_modified_time'] ?? '');
            if ($lm === '') return null;
            return strtotime($lm) - time();
        } catch (Throwable $e) {
            return null;
        }
    }

    public function deleteUser(string $uuid): void { $this->login(); $this->api('DELETE', 'user/' . rawurlencode($uuid) . '/'); }

    public function subBase(): string {
        if (!empty($this->server['sub_base'])) return rtrim((string)$this->server['sub_base'], '/');
        return $this->discoverSubBase();
    }
    public function subUrl(string $uuid): string { return $this->subBase() . '/' . $uuid . '/'; }

    public function test(): array
    {
        $this->login();
        $me = $this->api('GET', 'me/');
        $sub = $this->discoverSubBase();
        return ['ok' => true, 'name' => $me['name'] ?? '', 'mode' => $me['mode'] ?? '', 'sub_base' => $sub];
    }
}
