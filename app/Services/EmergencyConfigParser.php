<?php

namespace App\Services;

use InvalidArgumentException;
use App\Support\RealityTransport;

/** Parses proxy links locally; importing a link never contacts its server. */
final class EmergencyConfigParser
{
    public function parse(string $input, string $name): array
    {
        $uri = trim($input);
        if ($uri === '' || strlen($uri) > 16384 || preg_match('/[\x00-\x1f\x7f]/', $uri) || str_contains(explode('#', $uri, 2)[0], ' ')) $this->invalid();
        $scheme = strtolower(explode('://', $uri, 2)[0]);
        if ($scheme === 'vmess') return $this->vmess($uri, $name);
        if ($scheme === 'ss') return $this->shadowsocks($uri, $name);
        $types = ['vless'=>'vless', 'trojan'=>'trojan', 'hysteria2'=>'hysteria', 'hy2'=>'hysteria', 'hysteria'=>'hysteria', 'tuic'=>'tuic', 'anytls'=>'anytls', 'socks'=>'socks', 'socks5'=>'socks', 'http'=>'http', 'https'=>'http'];
        if (!isset($types[$scheme])) $this->invalid('پروتکل این لینک پشتیبانی نمی‌شود.');
        $parts = parse_url($uri);
        if (!$parts || !isset($parts['host'], $parts['port'])) $this->invalid();
        $query = $this->query($parts['query'] ?? '');
        $username = rawurldecode($parts['user'] ?? '');
        $password = rawurldecode($parts['pass'] ?? '');
        if (preg_match('/[\x00-\x1f\x7f]/', $username)) $this->invalid();
        if ($types[$scheme] === 'http' && $username === '') $this->invalid('لینک کانفیگ را وارد کنید؛ آدرس سایت یا لینک اشتراک قابل ثبت نیست.');
        if ($types[$scheme] === 'socks' && $username !== '' && !isset($parts['pass'])) {
            $decoded = $this->decode($username);
            if (str_contains($decoded, ':')) [$username, $password] = explode(':', $decoded, 2);
        }
        $secret = in_array($types[$scheme], ['socks', 'http'], true) ? $password : $username;
        if (isset($parts['pass']) && in_array($types[$scheme], ['trojan','hysteria','anytls'], true)) $secret .= ':'.$password;
        if (in_array($types[$scheme], ['vless','trojan','anytls','tuic'], true) && $secret === '') $this->invalid('اطلاعات اتصال لینک کامل نیست.');
        $settings = $this->settings($query, in_array($types[$scheme], ['trojan','hysteria','tuic','anytls'], true) || $scheme === 'https');
        if ($types[$scheme] === 'vless') {
            $settings['flow'] = $query['flow'] ?? '';
            $encryption = $query['encryption'] ?? 'none';
            if ($encryption !== 'none') $settings['encryption'] = ['enabled'=>true, 'encryption'=>$encryption];
        }
        if ($types[$scheme] === 'hysteria') {
            $settings['version'] = $scheme === 'hysteria' ? 1 : 2;
            $secret = $secret ?: ($query['auth'] ?? '');
            if ($secret === '') $this->invalid('اطلاعات اتصال لینک کامل نیست.');
            $settings['tls'] = $settings['tls_settings'];
            $settings['obfs'] = ['open'=>!empty($query['obfs']), 'type'=>$query['obfs'] ?? '', 'password'=>$query['obfs-password'] ?? $query['obfsParam'] ?? ''];
            $settings['up_mbps'] = $this->positiveNumber($query['upmbps'] ?? $query['up'] ?? 100);
            $settings['down_mbps'] = $this->positiveNumber($query['downmbps'] ?? $query['down'] ?? 100);
            $settings['bandwidth'] = ['up'=>$settings['up_mbps'], 'down'=>$settings['down_mbps']];
            if (isset($query['mport'])) $settings['port_range'] = $query['mport'];
        }
        if ($types[$scheme] === 'tuic') {
            if ($password === '') $this->invalid('رمز اتصال TUIC در لینک موجود نیست.');
            $settings['version'] = 5;
            $settings['password'] = $password;
            $settings['tls'] = $settings['tls_settings'];
            $settings['alpn'] = explode(',', $query['alpn'] ?? 'h3');
            $settings['congestion_control'] = $query['congestion_control'] ?? $query['congestion-controller'] ?? $query['congestion_controller'] ?? 'cubic';
            $settings['udp_relay_mode'] = $query['udp_relay_mode'] ?? 'native';
        }
        if ($types[$scheme] === 'anytls') {
            $settings['tls'] = $settings['tls_settings'];
            $settings['alpn'] = isset($query['alpn']) ? explode(',', $query['alpn']) : ['h2','http/1.1'];
        }
        if (in_array($types[$scheme], ['socks','http'], true)) $settings['username'] = $username;
        return $this->server($name, $types[$scheme], $parts['host'], $parts['port'], $secret, $settings, $this->rename($uri, $name));
    }

    private function vmess(string $uri, string $name): array
    {
        try { $v = json_decode($this->decode(explode('#', substr($uri, 8), 2)[0]), true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { $this->invalid('لینک VMess معتبر نیست.'); }
        if (!is_array($v) || empty($v['add']) || empty($v['id']) || !isset($v['port'])) $this->invalid();
        foreach ($v as $value) if (is_array($value) || is_object($value)) $this->invalid();
        $q = ['type'=>$v['net'] ?? 'tcp', 'security'=>($v['tls'] ?? '') === 'tls' ? 'tls' : 'none', 'sni'=>$v['sni'] ?? '', 'host'=>$v['host'] ?? '', 'path'=>$v['path'] ?? '', 'alpn'=>$v['alpn'] ?? '', 'fp'=>$v['fp'] ?? '', 'allowInsecure'=>$v['allowInsecure'] ?? false];
        if (($q['type'] ?? '') === 'grpc') $q['serviceName'] = $v['path'] ?? '';
        if (($q['type'] ?? '') === 'tcp' && ($v['type'] ?? '') === 'http') $q['headerType'] = 'http';
        $settings = $this->settings($q);
        $settings['alter_id'] = (int) ($v['aid'] ?? 0);
        $settings['security'] = $v['scy'] ?? 'auto';
        $v['ps'] = $name;
        $renamed = 'vmess://'.base64_encode(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $this->server($name, 'vmess', $v['add'], $v['port'], $v['id'], $settings, $renamed);
    }

    private function shadowsocks(string $uri, string $name): array
    {
        $main = explode('#', substr($uri, 5), 2)[0];
        [$address, $queryText] = array_pad(explode('?', $main, 2), 2, '');
        if (!str_contains($address, '@')) $address = $this->decode(rtrim($address, '/'));
        $parts = parse_url('ss://'.$address);
        if (!$parts || !isset($parts['host'], $parts['port'], $parts['user'])) $this->invalid();
        $credential = rawurldecode($parts['user']);
        if (isset($parts['pass'])) $credential .= ':'.rawurldecode($parts['pass']);
        elseif (!str_contains($credential, ':')) $credential = $this->decode($credential);
        if (!str_contains($credential, ':')) $this->invalid();
        [$cipher, $password] = explode(':', $credential, 2);
        if ($cipher === '' || $password === '') $this->invalid();
        $q = $this->query($queryText);
        $settings = ['cipher'=>$cipher, 'tls'=>0, 'network'=>'tcp', 'network_settings'=>[]];
        if (!empty($q['plugin'])) {
            [$settings['plugin'], $settings['plugin_opts']] = array_pad(explode(';', $q['plugin'], 2), 2, '');
        }
        return $this->server($name, 'shadowsocks', $parts['host'], $parts['port'], $password, $settings, $this->rename($uri, $name));
    }

    private function settings(array $q, bool $tlsDefault = false): array
    {
        $network = strtolower($q['type'] ?? $q['network'] ?? 'tcp');
        if ($network === 'raw') $network = 'tcp';
        if (!in_array($network, ['tcp','ws','grpc','http','h2','httpupgrade','xhttp','kcp','quic'], true)) $this->invalid('نوع انتقال این لینک پشتیبانی نمی‌شود.');
        $security = strtolower($q['security'] ?? ($tlsDefault ? 'tls' : 'none'));
        if (!in_array($security, ['none','tls','reality',''], true)) $this->invalid();
        $tls = ['server_name'=>$q['sni'] ?? $q['peer'] ?? $q['serverName'] ?? '', 'allow_insecure'=>$this->boolean($q['allowInsecure'] ?? $q['insecure'] ?? false)];
        if (isset($q['alpn']) && $q['alpn'] !== '') $tls['alpn'] = explode(',', $q['alpn']);
        if (!empty($q['fp'])) $tls['fingerprint'] = $q['fp'];
        if (!empty($q['ech'])) $tls['ech'] = $q['ech'];
        foreach (['verifyPeerCertByName','pinnedPeerCertSha256'] as $key) if (!empty($q[$key])) $tls[$key] = $q[$key];
        $ns = match ($network) {
            'ws' => ['path'=>$q['path'] ?? '/', 'headers'=>isset($q['host']) ? ['Host'=>$q['host']] : []],
            'grpc' => ['serviceName'=>$q['serviceName'] ?? $q['path'] ?? '', 'multiMode'=>($q['mode'] ?? '') === 'multi'],
            'http','h2','httpupgrade','xhttp' => ['path'=>$q['path'] ?? '/', 'host'=>$q['host'] ?? '', 'mode'=>$q['mode'] ?? 'auto'],
            'tcp' => ['header'=>['type'=>$q['headerType'] ?? 'none', 'request'=>['path'=>[$q['path'] ?? '/'], 'headers'=>['Host'=>[$q['host'] ?? '']]]]],
            'kcp' => ['seed'=>$q['seed'] ?? '', 'header'=>['type'=>$q['headerType'] ?? 'none']],
            'quic' => ['security'=>$q['quicSecurity'] ?? 'none', 'key'=>$q['key'] ?? '', 'header'=>['type'=>$q['headerType'] ?? 'none']],
        };
        if (isset($q['extra'])) {
            $extra = json_decode($q['extra'], true);
            if (!is_array($extra)) $this->invalid('تنظیمات تکمیلی لینک معتبر نیست.');
            $ns['extra'] = $extra;
        }
        $settings = ['network'=>$network, 'network_settings'=>$ns, 'tls'=>$security === 'reality' ? 2 : ($security === 'tls' ? 1 : 0), 'tls_settings'=>$tls];
        if (!RealityTransport::isSupported($settings)) $this->invalid(RealityTransport::ERROR);
        if (!empty($q['fp'])) $settings['utls'] = ['enabled'=>true, 'fingerprint'=>$q['fp']];
        if ($security === 'reality') {
            if (empty($q['pbk'])) $this->invalid('کلید عمومی Reality در لینک موجود نیست.');
            $settings['reality_settings'] = ['server_name'=>$tls['server_name'], 'public_key'=>$q['pbk'], 'short_id'=>$q['sid'] ?? '', 'fingerprint'=>$q['fp'] ?? 'chrome', 'spider_x'=>$q['spx'] ?? '/', 'allow_insecure'=>$tls['allow_insecure']];
        }
        return $settings;
    }

    private function server(string $name, string $type, mixed $host, mixed $port, mixed $secret, array $settings, string $uri): array
    {
        if (!is_string($host) || $host === '' || strlen($host) > 255 || preg_match('/[\s\x00-\x1f\x7f<>"\x27\\\\]/', $host)) $this->invalid();
        if (filter_var($port, FILTER_VALIDATE_INT) === false || (int) $port < 1 || (int) $port > 65535 || !is_scalar($secret) || preg_match('/[\x00-\x1f\x7f]/', (string) $secret)) $this->invalid();
        $server = ['name'=>$name, 'type'=>$type, 'host'=>trim($host, '[]'), 'port'=>(int) $port, 'password'=>(string) $secret, 'protocol_settings'=>$settings, 'rate'=>0, 'tags'=>[], '_emergency'=>true, '_emergency_uri'=>$uri];
        if (!empty($settings['port_range'])) $server['ports'] = $settings['port_range'];
        return $server;
    }

    private function query(string $text): array
    {
        parse_str($text, $q);
        foreach ($q as $value) if (!is_scalar($value) || preg_match('/[\x00-\x1f\x7f]/', (string) $value)) $this->invalid();
        return $q;
    }

    private function decode(string $text): string
    {
        $text = strtr($text, '-_', '+/');
        $decoded = base64_decode($text.str_repeat('=', (4 - strlen($text) % 4) % 4), true);
        if ($decoded === false) $this->invalid();
        return $decoded;
    }

    private function rename(string $uri, string $name): string
    {
        return explode('#', $uri, 2)[0].'#'.rawurlencode($name);
    }

    private function boolean(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['1','true','yes'], true);
    }

    private function positiveNumber(mixed $value): int
    {
        return max(1, (int) preg_replace('/[^0-9]/', '', (string) $value));
    }

    private function invalid(string $message = 'لینک کانفیگ معتبر نیست؛ یک لینک کامل اتصال وارد کنید.'): never
    {
        throw new InvalidArgumentException($message);
    }
}
