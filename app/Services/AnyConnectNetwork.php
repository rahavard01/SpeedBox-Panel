<?php

namespace App\Services;

use App\Models\{AnyConnectInstance, WireGuardInstance};

final class AnyConnectNetwork
{
    /** Allocate while holding the managed-server rows for both sides. */
    public function allocate(array $settings): array
    {
        $exit = (int) $settings['managed_server_id'];
        $entry = (int) ($settings['ingress_server_id'] ?? 0);
        $hosts = $entry ? [$exit, $entry] : [$exit];
        $ports = $networks = [];
        foreach ([AnyConnectInstance::class, WireGuardInstance::class] as $model) {
            foreach ($model::where(fn ($q) => $q->whereIn('managed_server_id', $hosts)
                ->orWhereIn('ingress_server_id', $hosts))->get() as $row) {
                $foreign = (int) $row->managed_server_id;
                $iran = (int) $row->ingress_server_id;
                $ports[$foreign][] = (int) $row->listen_port;
                $networks[$foreign][] = $row->network;
                if ($iran) {
                    $port = (int) ($row->link_port ?? $row->tunnel_port);
                    $ports[$foreign][] = $port;
                    $ports[$iran][] = $port;
                    $ports[$iran][] = (int) $row->endpoint_port;
                    $networks[$foreign][] = $row->link_network;
                    $networks[$iran][] = $row->link_network;
                }
            }
        }

        abort_if(in_array((int) $settings['listen_port'], $ports[$exit] ?? [], true), 422, 'پورت خروجی روی سرور رزرو شده است.');
        abort_if($entry && in_array((int) $settings['endpoint_port'], $ports[$entry] ?? [], true), 422, 'پورت ورودی روی سرور رزرو شده است.');

        $exitNetworks = array_filter($networks[$exit] ?? []);
        $network = $settings['network'] ?? null;
        if ($network !== null) {
            abort_unless(self::privateNetwork($network, 24), 422, 'شبکهٔ داخلی باید یک شبکهٔ خصوصی IPv4 با پیشوند /24 باشد.');
            abort_unless(self::available($network, $exitNetworks), 422, 'شبکهٔ داخلی با یکی از شبکه‌های رزروشدهٔ سرور خروجی تداخل دارد.');
        } else {
            $network = self::freeNetwork($exitNetworks, '10.90', 24);
        }
        $settings['network'] = $network;
        $settings['link_network'] = null;
        if ($entry) {
            $settings['link_network'] = self::freeNetwork([...$exitNetworks, ...array_filter($networks[$entry] ?? []), $network], '10.251', 30);
            $occupied = [...($ports[$exit] ?? []), ...($ports[$entry] ?? []), (int) $settings['listen_port'], (int) $settings['endpoint_port']];
            $port = isset($settings['link_port']) ? (int) $settings['link_port'] : null;
            if ($port === null) {
                for ($candidate = 51821; $candidate < 52000; $candidate++) {
                    if (!in_array($candidate, $occupied, true)) {
                        $port = $candidate;
                        break;
                    }
                }
                abort_if($port === null, 422, 'پورت ارتباط دو سرور آزاد یافت نشد. پورت دلخواه وارد کنید.');
            }
            abort_if(in_array($port, $occupied, true), 422, 'پورت ارتباط دو سرور اشغال است یا با پورت اتصال کاربران یکسان است.');
            $settings['link_port'] = $port;
        } else {
            $settings['link_port'] = null;
        }
        return $settings;
    }

    private static function freeNetwork(array $used, string $base, int $prefix): string
    {
        for ($subnet = 1; $subnet <= 254; $subnet++) {
            for ($tail = 0; $tail < 256; $tail += 1 << (32 - $prefix)) {
                $candidate = "$base.$subnet.$tail/$prefix";
                if (self::available($candidate, $used)) return $candidate;
            }
        }
        abort(422, 'شبکهٔ آزاد AnyConnect یافت نشد.');
    }

    private static function privateNetwork(string $value, int $prefix): bool
    {
        [$address, $bits] = array_pad(explode('/', $value, 2), 2, '');
        $ip = ip2long($address);
        return $bits === (string) $prefix && $ip !== false
            && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $address)
            && ($ip & ((1 << (32 - $prefix)) - 1)) === 0;
    }

    private static function available(string $candidate, array $used): bool
    {
        [$ip, $bits] = explode('/', $candidate);
        foreach ($used as $other) {
            [$otherIp, $otherBits] = array_pad(explode('/', $other, 2), 2, '');
            $prefix = min((int) $bits, (int) $otherBits);
            if ($prefix > 0 && ip2long($otherIp) !== false) {
                $shift = 32 - $prefix;
                if ((ip2long($ip) >> $shift) === (ip2long($otherIp) >> $shift)) return false;
            }
        }
        return true;
    }
}
