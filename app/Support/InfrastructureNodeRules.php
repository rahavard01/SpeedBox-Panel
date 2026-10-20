<?php

namespace App\Support;

use App\Models\ProxyServer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class InfrastructureNodeRules
{
    private const UTLS_RULES = [
        'utls.enabled' => 'nullable|boolean',
        'utls.fingerprint' => 'nullable|string',
    ];

    private const MULTIPLEX_RULES = [
        'multiplex.enabled' => 'nullable|boolean',
        'multiplex.protocol' => 'nullable|string',
        'multiplex.max_connections' => 'nullable|integer',
        'multiplex.min_streams' => 'nullable|integer',
        'multiplex.max_streams' => 'nullable|integer',
        'multiplex.padding' => 'nullable|boolean',
        'multiplex.brutal.enabled' => 'nullable|boolean',
        'multiplex.brutal.up_mbps' => 'nullable|integer',
        'multiplex.brutal.down_mbps' => 'nullable|integer',
    ];

    private const ECH_RULES = [
        'enabled' => 'nullable|boolean',
        'config' => 'nullable|string',
        'query_server_name' => 'nullable|string',
        'key' => 'nullable|string',
    ];

    private const REALITY_RULES = [
        'reality_settings.allow_insecure' => 'nullable|boolean',
        'reality_settings.server_name' => 'nullable|string',
        'reality_settings.server_port' => 'nullable|integer',
        'reality_settings.public_key' => 'nullable|string',
        'reality_settings.private_key' => 'nullable|string',
        'reality_settings.short_id' => 'nullable|string',
    ];

    private const PROTOCOL_RULES = [
        'shadowsocks' => [
            'cipher' => 'required|string',
            'obfs' => 'nullable|string',
            'obfs_settings.path' => 'nullable|string',
            'obfs_settings.host' => 'nullable|string',
            'plugin' => 'nullable|string',
            'plugin_opts' => 'nullable|string',
        ],
        'vmess' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'rules' => 'nullable|array',
        ],
        'trojan' => [
            'tls' => 'nullable|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'server_name' => 'nullable|string',
            'allow_insecure' => 'nullable|boolean',
        ],
        'hysteria' => [
            'version' => 'required|integer|in:1,2',
            'alpn' => 'nullable|string',
            'obfs.open' => 'nullable|boolean',
            'obfs.type' => 'string|nullable',
            'obfs.password' => 'string|nullable',
            'bandwidth.up' => 'nullable|integer',
            'bandwidth.down' => 'nullable|integer',
            'hop_interval' => 'integer|nullable',
            'port_range' => 'nullable|string',
        ],
        'vless' => [
            'tls' => 'required|integer',
            'network' => 'required|string',
            'network_settings' => 'nullable|array',
            'flow' => 'nullable|string',
            'encryption' => 'nullable|array',
            'encryption.enabled' => 'nullable|boolean',
            'encryption.encryption' => 'nullable|string',
            'encryption.decryption' => 'nullable|string',
        ],
        'socks' => [
            'tls' => 'nullable|integer',
        ],
        'naive' => [
            'tls' => 'required|integer',
        ],
        'http' => [
            'tls' => 'required|integer',
        ],
        'tuic' => [
            'version' => 'nullable|integer',
            'congestion_control' => 'nullable|string',
            'alpn' => 'nullable|array',
            'udp_relay_mode' => 'nullable|string',
        ],
        'mieru' => [
            'transport' => 'required|string|in:TCP,UDP',
            'traffic_pattern' => 'nullable|string',
        ],
        'anytls' => [
            'tls' => 'nullable|array',
            'alpn' => 'nullable|string',
            'padding_scheme' => 'nullable|array',
        ],
    ];

    private function getProtocolRules(string $type): array
    {
        $rules = self::PROTOCOL_RULES[$type] ?? [];

        return match ($type) {
            'vmess' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'trojan' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: true),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'hysteria' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'tuic' => array_merge(
                $rules,
                $this->buildTlsObjectRules(),
            ),
            'mieru' => array_merge(
                $rules,
                self::MULTIPLEX_RULES,
            ),
            'vless' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(),
                self::REALITY_RULES,
                self::MULTIPLEX_RULES,
                self::UTLS_RULES,
            ),
            'socks', 'naive', 'http' => array_merge(
                $rules,
                $this->buildTlsSettingsRules(includeRoot: $type !== 'socks'),
            ),
            'anytls' => array_merge(
                $rules,
                $this->buildTlsObjectRules(includeRoot: true),
            ),
            default => $rules,
        };
    }

    private function buildTlsSettingsRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls_settings' => 'nullable|array'] : [],
            [
                'tls_settings.server_name' => 'nullable|string',
                'tls_settings.allow_insecure' => 'nullable|boolean',
                'tls_settings.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls_settings.ech.', self::ECH_RULES),
        );
    }

    private function buildTlsObjectRules(bool $includeRoot = false): array
    {
        return array_merge(
            $includeRoot ? ['tls' => 'nullable|array'] : [],
            [
                'tls.server_name' => 'nullable|string',
                'tls.allow_insecure' => 'nullable|boolean',
                'tls.pinned_peer_cert_sha256' => [
                    'nullable',
                    'string',
                    'regex:/^[A-Fa-f0-9]{64}$/',
                ],
                'tls.ech' => 'nullable|array',
            ],
            $this->prefixRules('tls.ech.', self::ECH_RULES),
        );
    }

    private function prefixRules(string $prefix, array $rules): array
    {
        $result = [];
        foreach ($rules as $field => $rule) {
            $result[$prefix . $field] = $rule;
        }
        return $result;
    }


    public static function validate(Request $request, ?ProxyServer $node = null): array
    {
        if ((int) $request->input('parent_id') === 0) $request->merge(['parent_id' => null]);
        $rules = self::protocolRules((string) $request->input('type'));
        $rules = array_merge($rules, [
            'name' => 'required|string|max:100', 'type' => ['required', Rule::in(ProxyServer::TYPES)],
            'kernel' => ['required', Rule::in(ProxyServer::KERNELS)],
            'machine_id' => 'nullable|integer|exists:v2_server_machine,id',
            'warp_enabled' => 'sometimes|boolean',
            'host' => 'required|string|max:255', 'port' => 'required|string|max:64',
            'server_port' => ['required', 'integer', 'between:1,65535', Rule::unique('v2_server')->where(fn ($q) => $q->where('machine_id', $request->input('machine_id'))->where('enabled', true)->where(fn ($p) => $p->whereNull('parent_id')->orWhere('parent_id', 0)))->ignore($node?->id)],
            'rate' => 'required|numeric|min:0|max:9999', 'transfer_enable' => 'nullable|integer|min:0',
            'code' => ['nullable','string','max:255',Rule::unique('v2_server')->where('type', $request->input('type'))->ignore($node?->id)],
            'parent_id' => 'nullable|integer|exists:v2_server,id',
            'group_ids' => 'nullable|array', 'group_ids.*' => 'integer|exists:v2_server_group,id',
            'route_ids' => 'nullable|array', 'route_ids.*' => 'integer|exists:v2_server_route,id',
            'tags' => 'nullable|array', 'tags.*' => 'string|max:100',
            'rate_time_enable' => 'required|boolean', 'rate_time_ranges' => 'nullable|array',
            'rate_time_ranges.*.start' => 'required|date_format:H:i', 'rate_time_ranges.*.end' => 'required|date_format:H:i',
            'rate_time_ranges.*.rate' => 'required|numeric|min:0|max:9999',
            'protocol_settings' => 'present|array', 'custom_outbounds' => 'nullable|array', 'custom_routes' => 'nullable|array',
            'custom_outbounds.*' => 'array', 'custom_outbounds.*.tag' => 'required|string|distinct:ignore_case',
            'custom_outbounds.*.protocol' => 'required|string', 'custom_outbounds.*.settings' => 'required|array',
            'custom_outbounds.*.proxy_tag' => 'nullable|string', 'custom_routes.*' => 'array',
            'cert_config' => 'nullable|array', 'cert_config.cert_mode' => 'nullable|in:none,http,dns,self,file,content',
            'cert_config.domain' => 'nullable|string|max:253', 'cert_config.email' => 'nullable|email',
            'cert_config.dns_env' => 'nullable|array', 'cert_config.dns_provider' => 'nullable|string',
            'cert_config.dns_env.*' => 'nullable|string',
            'cert_config.http_port' => 'nullable|integer|between:1,65535',
            'cert_config.cert_content' => 'nullable|string', 'cert_config.key_content' => 'nullable|string',
            'cert_config.cert_file' => 'nullable|string', 'cert_config.key_file' => 'nullable|string',
            'protocol_settings.listen_address' => 'nullable|ip',
            'show' => 'required|boolean', 'enabled' => 'required|boolean', 'sort' => 'nullable|integer|min:0',
        ]);
        if ($request->filled('parent_id') || !$request->boolean('enabled') || !$request->filled('machine_id')) $rules['server_port'] = 'required|integer|between:1,65535';
        $data = Validator::make($request->all(), $rules, [
            'required' => 'وارد کردن :attribute الزامی است.', 'in' => 'مقدار :attribute معتبر نیست.',
            'integer' => ':attribute باید عدد صحیح باشد.', 'numeric' => ':attribute باید عدد باشد.',
            'boolean' => ':attribute باید روشن یا خاموش باشد.', 'array' => 'ساختار :attribute معتبر نیست.',
            'string' => ':attribute باید متن باشد.', 'min' => 'مقدار :attribute کمتر از حد مجاز است.',
            'max' => 'مقدار :attribute بیشتر از حد مجاز است.', 'between' => ':attribute خارج از بازه مجاز است.',
            'exists' => 'گزینه :attribute دیگر وجود ندارد.', 'unique' => ':attribute قبلاً استفاده شده است.',
            'distinct' => 'مقدار :attribute تکراری است.', 'present' => 'تنظیمات :attribute ارسال نشده است.',
            'email' => 'آدرس ایمیل معتبر نیست.', 'regex' => 'قالب :attribute معتبر نیست.', 'ip' => 'آدرس IP معتبر نیست.',
            'date_format' => 'ساعت باید با قالب ۲۴ ساعته HH:mm وارد شود.',
        ], ['name'=>'نام نود','machine_id'=>'سرور مقصد','server_port'=>'پورت سرور','code'=>'شناسه سفارشی','parent_id'=>'نود والد','rate'=>'ضریب ترافیک'])->validate();
        $data['protocol_settings'] ??= [];
        if (!empty($data['parent_id'])) {
            $parent = ProxyServer::findOrFail($data['parent_id']);
            if ($parent->id === $node?->id || $parent->parent_id || $parent->type !== $data['type']) {
                throw ValidationException::withMessages(['parent_id'=>'والد باید نود اصلی و هم‌پروتکل باشد.']);
            }
            if ($node && ProxyServer::where('parent_id',$node->id)->exists()) throw ValidationException::withMessages(['parent_id'=>'نودی که زیرمجموعه دارد نمی‌تواند زیرمجموعه شود.']);
            $data['machine_id'] = $parent->machine_id;
            $data['kernel'] = $parent->kernel;
            $data['rate'] = $parent->rate;
            $data['warp_enabled'] = (bool) $parent->warp_enabled;
        }
        $data['warp_enabled'] ??= (bool) $node?->warp_enabled;
        if ($data['warp_enabled'] && !isset($parent)) {
            $machine = \App\Models\ServerMachine::find($data['machine_id'] ?? 0);
            if (!$machine?->supportsWarp()) {
                throw ValidationException::withMessages(['warp_enabled' => 'پشتیبانی WARP هنوز از طرف این سرور تأیید نشده است؛ ابتدا وضعیت نرم‌افزار سرور را در بخش WARP بررسی کنید.']);
            }
            $newTarget = !$node?->warp_enabled || (int) $node?->machine_id !== (int) $machine->id || (bool) $node?->parent_id;
            if ($newTarget && (!data_get($machine->warp_status, 'ready') || (int) data_get($machine->warp_status, 'checked_at') < time() - 300)) {
                throw ValidationException::withMessages(['warp_enabled' => 'ابتدا آماده‌سازی یا تست اتصال WARP را روی سرور مقصد انجام دهید؛ نتیجه موفق باید مربوط به پنج دقیقه اخیر باشد.']);
            }
        }
        foreach ($data['custom_outbounds'] ?? [] as $outbound) {
            if (strtolower(trim((string) ($outbound['tag'] ?? ''))) === 'speedbox-warp') {
                throw ValidationException::withMessages(['custom_outbounds' => 'تگ speedbox-warp برای خروجی خودکار WARP رزرو شده است؛ برای خروجی دستی تگ دیگری انتخاب کنید.']);
            }
        }
        if ($node && $node->type !== $data['type'] && ProxyServer::where('parent_id', $node->id)->exists()) throw ValidationException::withMessages(['type'=>'ابتدا نودهای زیرمجموعه را منتقل کنید؛ پروتکل والد باید با آن‌ها یکی باشد.']);
        if ($data['kernel'] === 'xray' && in_array($data['type'], ['tuic','anytls','naive','mieru'], true)) {
            throw ValidationException::withMessages(['kernel'=>'این پروتکل به هسته Sing-box نیاز دارد.']);
        }
        $runtimeSettings = isset($parent) ? $parent->protocol_settings : $data['protocol_settings'];
        foreach ([$runtimeSettings, $data['protocol_settings']] as $settings) {
            $compatibility = NodeKernelCapabilities::resolve($data['type'], (array) $settings);
            if (!in_array($data['kernel'], $compatibility['kernels'], true)) {
                throw ValidationException::withMessages(['kernel' => $compatibility['reason'] ?: 'تنظیمات انتخاب‌شده با این هسته سازگار نیست.']);
            }
        }
        if (!RealityTransport::isSupported((array) $runtimeSettings)) {
            throw ValidationException::withMessages(['protocol_settings.network' => RealityTransport::ERROR]);
        }
        if ($data['type'] === 'vless' && data_get($runtimeSettings, 'encryption.enabled')) {
            if ($data['kernel'] !== 'xray') {
                throw ValidationException::withMessages(['kernel'=>'رمزنگاری VLESS به هسته Xray نیاز دارد.']);
            }
            foreach (['encryption', 'decryption'] as $key) {
                $value = trim((string) data_get($runtimeSettings, "encryption.{$key}"));
                if ($value === '' || $value === 'none') {
                    throw ValidationException::withMessages(['protocol_settings.encryption'=>'برای رمزنگاری VLESS، هر دو مقدار سمت کاربر و سرور را وارد یا از سرور تولید کنید.']);
                }
            }
        }
        if (in_array(data_get($data,'protocol_settings.network'), ['xhttp','kcp'], true) && $data['kernel'] !== 'xray') {
            throw ValidationException::withMessages(['kernel'=>'این پروتکل انتقال به هسته Xray نیاز دارد.']);
        }
        $httpHeader = strtolower(trim((string) data_get($runtimeSettings, 'network_settings.header.type'))) === 'http';
        if (in_array(data_get($runtimeSettings, 'network', 'tcp'), ['tcp', 'raw'], true) && $httpHeader) {
            if ($data['kernel'] !== 'xray') {
                throw ValidationException::withMessages(['kernel'=>'قالب TCP با سرآیند HTTP به هسته Xray نیاز دارد.']);
            }
            if ($data['type'] === 'vless' && (int) data_get($runtimeSettings, 'tls', 0) === 0 && trim((string) data_get($runtimeSettings, 'flow')) !== '') {
                throw ValidationException::withMessages(['protocol_settings.flow'=>'برای VLESS با TCP HTTP بدون TLS، کنترل جریان را روی «بدون جریان» قرار دهید.']);
            }
            if (!isset($parent)) $data['protocol_settings']['network_settings']['header']['type'] = 'http';
        }
        foreach (array_filter([$data['port'], data_get($data, 'protocol_settings.port_range')]) as $ports) {
            if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', (string) $ports, $match) || (int) $match[1] < 1 || (int) $match[1] > 65535 || (isset($match[2]) && ((int) $match[2] < (int) $match[1] || (int) $match[2] > 65535))) {
                throw ValidationException::withMessages(['port'=>'پورت باید بین ۱ و ۶۵۵۳۵ یا یک بازه معتبر مانند 10000-20000 باشد.']);
            }
        }
        foreach ($data['rate_time_ranges'] ?? [] as $range) {
            if ($range['end'] < $range['start']) throw ValidationException::withMessages(['rate_time_ranges'=>'پایان بازه باید بعد از شروع باشد؛ بازه عبوری از نیمه‌شب را به دو بازه تقسیم کنید.']);
        }
        if (!empty($data['machine_id']) && \App\Models\ManagedServer::where('machine_id',$data['machine_id'])->where('status','removing')->exists()) {
            throw ValidationException::withMessages(['machine_id'=>'این سرور در حال حذف است.']);
        }
        if (!isset($data['code']) || $data['code'] === '') $data['code'] = null;
        return $data;
    }

    private static function protocolRules(string $type): array
    {
        $result = [];
        foreach ((new self)->getProtocolRules($type) as $key => $rule) $result['protocol_settings.'.$key] = $rule;
        return $result;
    }
}
