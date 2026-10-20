<?php

namespace App\Support;

final class NodeKernelCapabilities
{
    /** Shared with the agent and delivered by the nodes API to the editor. */
    public static function definition(): array
    {
        return json_decode(file_get_contents(base_path('agent/internal/model/kernel-capabilities.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function resolve(string $type, array $settings): array
    {
        $definition = self::definition();
        $allowed = $definition['protocols'][$type] ?? [];
        $reasons = [];
        $transport = in_array($type, ['vmess', 'vless', 'trojan'], true);
        $network = strtolower(trim((string) ($settings['network'] ?? 'tcp')));
        $features = [
            'hysteria1' => $type === 'hysteria' && (int) ($settings['version'] ?? 2) === 1,
            'tuic_legacy' => $type === 'tuic' && (int) ($settings['version'] ?? 5) !== 5,
            'xray_transport' => $transport && in_array($network, ['xhttp', 'splithttp', 'kcp'], true),
            'vless_encryption' => $type === 'vless' && filter_var(data_get($settings, 'encryption.enabled', false), FILTER_VALIDATE_BOOLEAN),
            'tcp_http' => $transport && in_array($network, ['', 'tcp', 'raw'], true) && strtolower(trim((string) data_get($settings, 'network_settings.header.type'))) === 'http',
            'ss2022_chacha' => $type === 'shadowsocks' && ($settings['cipher'] ?? '') === '2022-blake3-chacha20-poly1305',
        ];
        foreach ($features as $feature => $enabled) {
            if (!$enabled) continue;
            $rule = $definition['features'][$feature];
            $allowed = array_values(array_intersect($allowed, $rule['kernels']));
            $reasons[] = $rule['reason'];
        }
        if (!$reasons && count($allowed) === 1) $reasons[] = 'این پروتکل به هسته '.($allowed[0] === 'xray' ? 'Xray' : 'Sing-box').' نیاز دارد.';
        return ['kernels' => $allowed, 'reason' => implode(' ', $reasons)];
    }
}
