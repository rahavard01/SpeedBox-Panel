<?php

namespace App\Services;

use App\Models\AnyConnectInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class AnyConnectCertificate
{
    public function __construct(private readonly AnyConnectRemote $remote, private readonly TunnelCdnRemote $tools)
    {
    }

    public static function settings(Request $request, string $domain, ?array $previous = null, int $endpointPort = 443): ?array
    {
        if (!$request->has('certificate')) return $previous;
        $input = $request->validate([
            'certificate' => 'required|array',
            'certificate.mode' => 'required|in:existing,dns,http,content',
            'certificate.http_port' => 'nullable|integer|between:1,65535',
            'certificate.dns_provider' => 'nullable|in:arvancloud,cloudflare',
            'certificate.email' => 'nullable|email|max:253',
            'certificate.dns_env' => 'nullable|array|max:2',
            'certificate.dns_env.*' => 'required|string|max:4096',
            'certificate.cert_content' => 'nullable|string|max:65536',
            'certificate.key_content' => 'nullable|string|max:32768',
        ])['certificate'];
        $mode = $input['mode'];
        if ($mode === 'existing') return null;
        if ($mode === 'http') {
            $httpPort = (int) ($input['http_port'] ?? $previous['http_port'] ?? 80);
            if ($endpointPort === 80 || $endpointPort === $httpPort) {
                self::invalid('پورت ورودی AnyConnect باید با پورت عمومی ۸۰ و پورت چالش HTTP متفاوت باشد.');
            }
            return ['mode' => $mode, 'email' => $input['email'] ?? '', 'http_port' => $httpPort];
        }
        if ($mode === 'content') {
            $cert = $input['cert_content'] ?? '';
            $key = $input['key_content'] ?? '';
            if (!$cert && !$key && ($previous['mode'] ?? null) === 'content') return $previous;
            try {
                TunnelCdn::validateCertificate($cert, $key, $domain);
            } catch (ValidationException $error) {
                self::invalid($error->validator->errors()->first());
            }
            return ['mode' => $mode, 'cert_content' => $cert, 'key_content' => $key];
        }

        $provider = $input['dns_provider'] ?? null;
        $required = match ($provider) {
            'arvancloud' => 'ARVANCLOUD_API_KEY',
            'cloudflare' => 'CLOUDFLARE_DNS_API_TOKEN',
            default => self::invalid('ارائه‌دهندهٔ DNS را انتخاب کنید.'),
        };
        $allowed = $provider === 'cloudflare' ? [$required, 'CLOUDFLARE_ZONE_API_TOKEN'] : [$required];
        $env = ($previous['dns_provider'] ?? null) === $provider ? $previous['dns_env'] : [];
        foreach ($input['dns_env'] ?? [] as $name => $value) {
            if (!in_array($name, $allowed, true) || str_contains($value, '********')) {
                self::invalid('کلید کامل دسترسی ارائه‌دهندهٔ DNS را وارد کنید.');
            }
            $env[$name] = $value;
        }
        if (empty($env[$required])) self::invalid('کلید دسترسی ارائه‌دهندهٔ DNS را وارد کنید.');
        return ['mode' => $mode, 'dns_provider' => $provider, 'email' => $input['email'] ?? '', 'dns_env' => $env];
    }

    public function prepare(AnyConnectInstance $instance, array $policy): ?string
    {
        $settings = $instance->certificate_config ?? ['mode' => 'existing'];
        $revision = hash('sha256', implode(':', [$instance->owner_id, $instance->server->updated_at,
            $instance->ingress?->updated_at, $instance->getRawOriginal('certificate_config')]));
        $cacheKey = 'anyconnect:certificate:'.$instance->id;
        $cached = Cache::get($cacheKey);
        if ($cached && $cached['revision'] === $revision) return $cached['error'];
        try {
            if ($settings['mode'] === 'http' && $instance->ingress_server_id) {
                abort_unless($instance->ingress, 422, 'سرور ورودی AnyConnect برای صدور گواهی پیدا نشد.');
                $this->tools->ensure($instance->ingress);
                $ingressPolicy = $policy;
                unset($ingressPolicy['radius_secret']);
                $issued = $this->remote->request($instance->ingress, 'issue_http_certificate',
                    $ingressPolicy + ['side' => 'iran', 'certificate' => $settings]);
                $settings = ['mode' => 'content', 'cert_content' => $issued['cert_content'], 'key_content' => $issued['key_content']];
            } elseif (in_array($settings['mode'], ['http', 'dns'], true)) {
                $this->tools->ensure($instance->server);
            }
            $this->remote->request($instance->server, 'prepare_certificate', $policy + ['certificate' => $settings]);
            $error = null;
        } catch (\Throwable $failure) {
            // Known helper failures are written without certificate data or DNS credentials.
            $error = mb_substr($failure->getMessage(), 0, 600);
        }
        Cache::put($cacheKey, ['revision' => $revision, 'error' => $error], $error === null ? 43200 : 600);
        return $error;
    }

    private static function invalid(string $message): never
    {
        throw ValidationException::withMessages(['certificate' => $message]);
    }
}
