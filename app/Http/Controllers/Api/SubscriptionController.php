<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProxyServer;
use App\Models\SubscriptionAccount;
use App\Protocols\General;
use App\Services\EmergencyConfigurations;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\InterceptResponseException;
use App\Services\RuntimeSetting;
use App\Services\SubscriptionFilter;
use App\Services\SubscriptionResetDay;
use App\Support\JalaliDateFormatter;
use App\Support\ProtocolManager;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SubscriptionController extends Controller
{
    private const PROTOCOL_PREFIXES = [
        'hysteria' => [1 => '[Hy]', 2 => '[Hy2]'],
        'vless' => '[vless]', 'shadowsocks' => '[ss]', 'vmess' => '[vmess]',
        'trojan' => '[trojan]', 'tuic' => '[tuic]', 'socks' => '[socks]', 'anytls' => '[anytls]',
    ];

    public function show(Request $request, ?string $token = null)
    {
        $token = $request->input('token', $token ?? $request->route('token'));
        if (empty($token)) return $this->tokenError('token is null');
        if (!is_string($token)) return $this->tokenError('token is error');
        $user = SubscriptionAccount::where('token', $token)->first();
        if (!$user) return $this->tokenError('token is error');

        try {
            return $this->withUser($request, $user, function () use ($request, $user) {
                HookManager::call('client.subscribe.before');
                $this->validateInput($request);
                if ($this->shouldRenderSubscriptionPage($request)) return $this->portal($request, $user);
                if ($user->transfer_enable - ($user->u + $user->d) <= 0) {
                    return $this->disabledResponse($user, '⛔ ترافیک شما به اتمام رسید ⛔');
                }
                if (!$this->isAvailable($user)) {
                    HookManager::call('client.subscribe.unavailable');
                    return $this->disabledResponse($user, '⛔️ اکانت شما منقضی شد ⛔️');
                }
                return $this->doSubscribe($request, $user);
            });
        } catch (InterceptResponseException $exception) {
            return $exception->getResponse();
        }
    }

    public function subscribe(Request $request)
    {
        return $this->show($request);
    }

    /** Preserve the fork's supplied-server-list extension point. */
    public function doSubscribe(Request $request, $user, $servers = null)
    {
        $user = $this->account($user);
        try {
            return $this->withUser($request, $user, function () use ($request, $user, $servers) {
                $this->validateInput($request);
                if ($servers === null) {
                    $servers = $this->serversFor($user);
                    $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
                }
                $types = SubscriptionFilter::types($request->input('types'));
                $keywords = SubscriptionFilter::keywords($request->input('filter'));
                $servers = array_values(array_filter($servers, fn ($server) => SubscriptionFilter::matches($server, $types, $keywords)));
                $servers = $this->speedBoxServers($servers, $request);
                $servers = $this->decorateServers($servers, $user);
                $servers = app(EmergencyConfigurations::class)->merge($servers, $types, $keywords);
                // Emergency rows are appended after node filtering; enforce the same client gate.
                $servers = $this->speedBoxServers($servers, $request);

                $client = $this->getClientInfo($request);
                $class = app('protocols.manager')->matchProtocolClassName($client['flag']) ?? General::class;
                $response = app()->make($class, [
                    'user' => $user, 'servers' => $servers,
                    'clientName' => $client['name'], 'clientVersion' => $client['version'],
                    'userAgent' => $request->userAgent(),
                ])->handle();
                if ($request->boolean('raw') && $class === General::class) {
                    $response->setContent(base64_decode($response->getContent()));
                }
                // Exporter-specific titles, SMART intervals and cache rules take priority.
                foreach ([
                    'Cache-Control' => 'no-store',
                    'Subscription-Userinfo' => "upload={$user->u}; download={$user->d}; total={$user->transfer_enable}; expire={$user->expired_at}",
                    'Profile-Title' => 'base64:'.base64_encode((string) RuntimeSetting::get('app_name', 'SpeedBox')),
                    'Profile-Update-Interval' => (string) RuntimeSetting::get('subscribe_update_interval', 6),
                ] as $header => $value) {
                    if (!$response->headers->has($header)) $response->headers->set($header, $value);
                }
                return $response;
            });
        } catch (InterceptResponseException $exception) {
            return $exception->getResponse();
        }
    }

    private function validateInput(Request $request): void
    {
        $request->validate([
            'types' => ['nullable', 'string'], 'filter' => ['nullable', 'string'], 'flag' => ['nullable', 'string'],
        ]);
    }

    private function account($user): SubscriptionAccount
    {
        if ($user instanceof SubscriptionAccount) return $user;
        $attributes = $user instanceof \Illuminate\Database\Eloquent\Model
            ? $user->getAttributes() : (is_array($user) ? $user : (array) $user);
        return (new SubscriptionAccount)->setRawAttributes($attributes);
    }

    private function withUser(Request $request, SubscriptionAccount $user, callable $callback)
    {
        $resolver = $request->getUserResolver();
        $guard = Auth::guard();
        $previous = $guard->user();
        $request->setUserResolver(fn () => $user);
        $guard->setUser($user);
        try {
            return $callback();
        } finally {
            $request->setUserResolver($resolver);
            if ($previous === null) $guard->forgetUser();
            else $guard->setUser($previous);
        }
    }

    private function tokenError(string $message)
    {
        return response()->json(['status' => 'fail', 'message' => $message, 'data' => null, 'error' => null], 403);
    }

    private function isAvailable(object $user): bool
    {
        return !$user->banned && $user->transfer_enable
            && \App\Services\SubscriptionValidity::unexpired($user->expired_at);
    }

    private function disabledResponse(SubscriptionAccount $user, string $message)
    {
        $name = str_replace(['@', '.com'], ['-', ''], $user->email);
        $links = [
            'vless://00000000-0000-0000-0000-000000000001@0.0.0.0:1?encryption=none&type=tcp#'.rawurlencode($message),
            'vless://00000000-0000-0000-0000-000000000000@0.0.0.0:1?encryption=none&type=tcp#'.rawurlencode("👤 USER ：{$name}"),
        ];
        return response(implode("\n", $links), 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }

    private function getClientInfo(Request $request): array
    {
        $flag = strtolower($request->input('flag') ?? $request->header('User-Agent', ''));
        $name = null;
        $version = null;
        /** @var ProtocolManager $manager */
        $manager = app('protocols.manager');
        $flags = $manager->getAllFlags();
        if (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $flag, $matches)) {
            $version = preg_replace('/^v/', '', $matches[2]);
            if (in_array(strtolower($matches[1]), $flags, true)) $name = strtolower($matches[1]);
        }
        if (!$name) {
            foreach (collect($flags)->sortByDesc(fn ($value) => strlen($value)) as $candidate) {
                if (stripos($flag, $candidate) === false) continue;
                $name = $candidate;
                if (!$version && preg_match('/'.preg_quote($candidate, '/').'[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/i', $flag, $matches)) {
                    $version = preg_replace('/^v/', '', $matches[1]);
                }
                break;
            }
        }
        if (!$version && preg_match('/\/v?(\d+(?:\.\d+){0,2})/', $flag, $matches)) $version = $matches[1];
        return ['flag' => $flag, 'name' => $name, 'version' => $version];
    }

    private function shouldRenderSubscriptionPage(Request $request): bool
    {
        if ($request->filled('flag') || $request->boolean('raw')) return false;
        if ($this->getClientInfo($request)['name']) return false;
        return str_contains(strtolower((string) $request->header('Accept', '')), 'text/html')
            && (strtolower((string) $request->header('Sec-Fetch-Dest', '')) === 'document'
                || strtolower((string) $request->header('Sec-Fetch-Mode', '')) === 'navigate');
    }

    private function portal(Request $request, SubscriptionAccount $user)
    {
        $used = max(0, (int) $user->u + (int) $user->d);
        $total = max(0, (int) $user->transfer_enable);
        $remaining = max(0, $total - $used);
        // One canonical URL for text, clipboard and QR, including URL hooks.
        // Request::url() loses ?token= on the legacy endpoint.
        $url = Helper::getSubscribeUrl((string) $user->token);
        $writer = new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(256), new \BaconQrCode\Renderer\Image\SvgImageBackEnd()));
        return response()->view('client.subscribe', [
            'username' => str_replace(['@', '.com'], ['-', ''], $user->email),
            'status' => !$user->banned && $remaining > 0 && \App\Services\SubscriptionValidity::unexpired($user->expired_at) ? 'active' : 'inactive',
            'data_used' => round($used / 1073741824, 2).' GB', 'data_limit' => round($total / 1073741824, 2).' GB',
            'data_remaining' => round($remaining / 1073741824, 2).' GB', 'usage_percent' => $total ? min(100, round($used / $total * 100, 1)) : 0,
            'expired_date' => \App\Services\SubscriptionValidity::hasExpiry($user->expired_at) ? JalaliDateFormatter::format((int) $user->expired_at, 'Y/m/d') : 'بدون تاریخ انقضا',
            'remaining_days' => $this->remainingCalendarDays($user),
            'device_limit' => $user->device_limit, 'reset_day' => app(SubscriptionResetDay::class)->forUser($user),
            'online_count' => app(\App\Services\AccountOnlineStatus::class)->counts([(int) $user->id])[$user->id] ?? 0,
            'subscription_url' => $url, 'qr_code' => base64_encode($writer->writeString($url)),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    private function remainingDays(object $user): ?int
    {
        // Keep the fork's existing information-node naming behavior unchanged.
        $days = $this->remainingCalendarDays($user);
        return $days !== null && $days < 0 ? null : $days;
    }

    private function remainingCalendarDays(object $user): ?int
    {
        if (!\App\Services\SubscriptionValidity::hasExpiry($user->expired_at)) return null;
        $timezone = config('app.timezone', 'Asia/Tehran');
        $expiry = \Carbon\CarbonImmutable::createFromTimestamp((int) $user->expired_at, $timezone)->startOfDay();
        return (int) \Carbon\CarbonImmutable::now($timezone)->startOfDay()->diffInDays($expiry, false);
    }

    private function decorateServers(array $servers, SubscriptionAccount $user): array
    {
        $showInfo = (bool) RuntimeSetting::get('show_info_to_server_enable', false);
        $showOnline = (bool) RuntimeSetting::get('show_online_to_server_enable', false);
        if ($servers && ($showInfo || $showOnline)) {
            preg_match('/^(\p{Regional_Indicator}{2})/u', $servers[0]['name'] ?? '', $matches);
            $flag = $matches[1] ?? '';
            $used = round($user->u / 1073741824, 2) + round($user->d / 1073741824, 2);
            $remaining = round(round($user->transfer_enable / 1073741824, 2) - $used, 2);
            $days = $this->remainingDays($user);
            $expiry = \App\Services\SubscriptionValidity::hasExpiry($user->expired_at) ? JalaliDateFormatter::format((int) $user->expired_at, 'Y-m-d') : __('Updating Soon ...');
            $account = str_replace(['@', '.com'], ['-', ''], $user->email);
            $names = $showInfo ? [
                "{$flag}☠️ Remaining Traffic  ：{$remaining} GB",
                "{$flag}👤 USER  ：{$account} ",
            ] : [];
            $reset = app(SubscriptionResetDay::class)->forUser($user);
            if ($showInfo && $reset) $names[] = "{$flag}♻️ Reset Traffic ：{$reset} Days";
            if ($showInfo) $names[] = "{$flag}⛔️️ Expire ：{$expiry} / {$days} Days";
            if ($showOnline) {
                $online = app(\App\Services\AccountOnlineStatus::class)->counts([(int) $user->id])[$user->id] ?? 0;
                $limit = (int) ($user->device_limit ?? 0);
                $names[] = "{$flag}🔌 Online : {$online} / ".($limit > 0 ? (string) $limit : '∞');
            }
            if ($showInfo && in_array((string) $days, ['0', '1', '2'], true)) {
                array_unshift($names, "{$flag}❌ اکانت شما رو به اتمام است ❌");
            }
            if ($showInfo && $remaining <= 0) array_unshift($names, "{$flag}⛔ ترافیک شما به اتمام رسید ⛔");
            elseif ($showInfo && $remaining <= 1) array_unshift($names, "{$flag}❌ ترافیک شما رو به اتمام است ❌");
            $first = $servers[0];
            $servers = array_merge(array_map(fn ($name) => array_replace($first, ['name' => $name]), $names), $servers);
        }
        if (RuntimeSetting::get('show_protocol_to_server_enable', false)) {
            foreach ($servers as &$server) {
                $prefix = self::PROTOCOL_PREFIXES[$server['type']] ?? '';
                if (is_array($prefix)) $prefix = $prefix[data_get($server, 'protocol_settings.version', 1)] ?? '';
                $server['name'] = $prefix.$server['name'];
            }
            unset($server);
        }
        return $servers;
    }

    private function speedBoxServers(array $servers, Request $request): array
    {
        $key = (string) config('services.speedbox.sub_key', '');
        $isSpeedBox = $key !== '' && hash_equals($key, (string) $request->header('X-SpeedBox-Key', ''));
        $result = [];
        foreach ($servers as $server) {
            $only = stripos($server['name'] ?? '', 'SBONLY') !== false;
            foreach ($server['tags'] ?? [] as $tag) {
                if (is_string($tag) && strcasecmp($tag, 'SBONLY') === 0) $only = true;
            }
            if ($only && !$isSpeedBox) continue;
            if ($isSpeedBox && isset($server['name'])) {
                $name = preg_replace('/\s*[\[\(]?\s*SBONLY\s*[\]\)]?\s*/i', ' ', $server['name']);
                $name = preg_replace('/\s{2,}/', ' ', $name);
                $server['name'] = trim(preg_replace('/\s*-\s*/', ' - ', $name));
            }
            $result[] = $server;
        }
        return $result;
    }

    private function serversFor(SubscriptionAccount $user): array
    {
        // The fork selects by visibility, own quota and group, not runtime-parent state.
        return ProxyServer::query()->with('parent')->where('show', true)
            ->where(fn ($q) => $q->whereNull('transfer_enable')->orWhere('transfer_enable', 0)->orWhereRaw('u + d < transfer_enable'))
            ->where(fn ($q) => $q->whereJsonContains('group_ids', (string) $user->group_id)->orWhereJsonContains('group_ids', (int) $user->group_id))
            ->orderBy('sort')->get()->map(function (ProxyServer $server) use ($user) {
                $data = $server->toArray();
                unset($data['parent']);
                $data['rate'] = $this->currentRate($server);
                $range = $server->type === 'hysteria' ? data_get($data, 'protocol_settings.port_range') : null;
                if ($range || str_contains((string) $server->port, '-')) {
                    $data['ports'] = $range ?: $server->port;
                    $data['port'] = Helper::randomPort($data['ports']);
                } else $data['port'] = (int) $server->port;
                $data['password'] = $user->uuid;
                $cipher = data_get($server->protocol_settings, 'cipher', '');
                if ($server->type === 'shadowsocks' && in_array($cipher, ['2022-blake3-aes-128-gcm', '2022-blake3-aes-256-gcm', '2022-blake3-chacha20-poly1305'], true)) {
                    $length = $cipher === '2022-blake3-aes-128-gcm' ? 16 : 32;
                    $created = ($server->parent_id ? $server->parent?->created_at : $server->created_at) ?? $server->created_at;
                    $timestamp = $created instanceof \DateTimeInterface ? $created->getTimestamp() : (int) $created;
                    $data['password'] = Helper::getServerKey($timestamp, $length).':'.Helper::uuidToBase64($user->uuid, $length);
                }
                return $data;
            })->all();
    }

    private function currentRate(ProxyServer $server): float
    {
        if (!$server->rate_time_enable) return (float) $server->rate;
        $time = now()->format('H:i');
        foreach ($server->rate_time_ranges ?? [] as $range) {
            if ($time >= $range['start'] && $time <= $range['end']) return (float) $range['rate'];
        }
        return (float) $server->rate;
    }
}
