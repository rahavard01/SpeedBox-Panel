<?php

namespace Illuminate\Http {
    class Request
    {
        public function __construct(private ?string $token = null) {}
        public function input(string $key, $default = null) { return $this->token ?? $default; }
        public function getSchemeAndHttpHost(): string { return 'https://panel.example.test'; }
    }
}
namespace App\Http\Controllers { class Controller {} }
namespace App\Models {
    final class PanelBotSetting
    {
        public static ?self $instance = null;
        public bool $enabled = false;
        public ?string $bot_token = null;
        public ?string $webhook_secret = null;
        public static function first(): ?self { return self::$instance; }
        public static function getSingleton(): self { return self::$instance ??= new self; }
        public function save(): void {}
    }
}
namespace Illuminate\Support {
    class Str
    {
        public static function random(int $length): string { return str_repeat('a', $length); }
    }
}
namespace Illuminate\Support\Facades {
    final class Cache
    {
        public static array $values = [];
        public static bool $busy = false;
        public static function get($key) { return self::$values[$key] ?? null; }
        public static function put($key, $value, $ttl): void { self::$values[$key] = $value; }
        public static function lock($name, $ttl) {
            return new class {
                public function get($callback) { return Cache::$busy ? false : $callback(); }
            };
        }
    }
    final class Http
    {
        public static array $calls = [];
        public static string $username = 'SpeedboxPartnerBot';
        public static string $webhook = 'https://panel.example.test/api/telegram/webhook';
        public static bool $setOk = true;
        public static function timeout($seconds): self { return new self; }
        public function asForm(): self { return $this; }
        public function get($url) { return $this->reply($url); }
        public function post($url, $body) { return $this->reply($url); }
        private function reply($url) {
            $method = basename($url);
            self::$calls[] = $method;
            return new class(match ($method) {
                'getMe' => ['ok'=>true, 'result'=>['is_bot'=>true, 'username'=>Http::$username]],
                'getWebhookInfo' => ['ok'=>true, 'result'=>['url'=>Http::$webhook]],
                'setWebhook' => ['ok'=>Http::$setOk],
                'deleteWebhook' => ['ok'=>true],
                default => throw new \LogicException($method),
            }) {
                public function __construct(private array $reply) {}
                public function ok(): bool { return true; }
                public function json(?string $key = null) { return $key === null ? $this->reply : data_get($this->reply, $key); }
                public function throw(): self { return $this; }
            };
        }
    }
}
namespace {
    use App\Http\Controllers\Api\Admin\BotAdminController;
    use App\Models\PanelBotSetting;
    use App\Services\PartnerBotEntry;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Http;

    function data_get($data, string $key) {
        foreach (explode('.', $key) as $part) $data = $data[$part] ?? null;
        return $data;
    }
    function config($key) { return $key === 'app.url' ? 'https://panel.example.test' : null; }
    function env($key) { return null; }
    function response() {
        return new class {
            public function json($body, $status = 200): array { return ['body'=>$body, 'status'=>$status]; }
        };
    }
    function check($condition, string $message): void {
        if (!$condition) throw new \RuntimeException($message);
    }
    $root = dirname(__DIR__, 2);
    require $root.'/app/Services/TelegramConfig.php';
    require $root.'/app/Services/PartnerBotEntry.php';
    require $root.'/app/Http/Controllers/Api/Admin/BotAdminController.php';

    $host = 'https://panel.example.test';
    $partner = (object) ['role'=>2];
    $link = 'https://t.me/SpeedboxPartnerBot';
    $controller = new BotAdminController();
    check(PartnerBotEntry::forPartner($partner, $host) === null && Http::$calls === [] && PanelBotSetting::$instance === null, 'Unconfigured panels never contact Telegram or create a setting');

    $setting = PanelBotSetting::getSingleton();
    $setting->enabled = true;
    $setting->bot_token = '123456:old-test-token';
    $setting->webhook_secret = 'old-secret';
    Cache::$busy = true;
    check(PartnerBotEntry::forPartner($partner, $host) === null && Http::$calls === [], 'Discovery does not double-call while a check is in progress');
    Cache::$busy = false;
    Http::$webhook = 'https://different-panel.example.test/api/telegram/webhook';
    check(PartnerBotEntry::forPartner($partner, $host) === null && Http::$calls === ['getWebhookInfo'], 'A bot connected to another panel is not shown');
    Cache::$values = [];
    Http::$webhook = $host.'/api/telegram/webhook';
    check(PartnerBotEntry::forPartner($partner, $host) === $link, 'Previously configured bots are discovered');
    $calls = Http::$calls;
    check(PartnerBotEntry::forPartner($partner, $host) === $link && Http::$calls === $calls, 'Dashboard polling reuses verification');
    check(PartnerBotEntry::forPartner((object) ['role'=>1], $host) === null, 'Admins have no partner CTA');
    check(PartnerBotEntry::forPartner((object) ['role'=>2, 'telegram_user_id'=>'321'], $host) === null, 'Existing linked partners have no CTA');
    check(PartnerBotEntry::forPartner((object) ['role'=>2, 'partner_bot_has_logged_in'=>true, 'telegram_user_id'=>null], $host) === null, 'A prior success still hides the CTA after logout');

    Http::$username = 'https://different.example.test';
    check($controller->setWebhook(new Request('777777:invalid-username'))['status'] === 422, 'Reject usernames that cannot produce a Telegram link');
    check($setting->bot_token === '123456:old-test-token', 'Invalid replacement leaves the prior bot in place');

    Http::$username = 'SecondPartnerBot';
    Http::$setOk = false;
    check($controller->setWebhook(new Request('777777:replacement'))['status'] === 422, 'HTTP 200 with Telegram ok=false is failure');
    check($setting->bot_token === '123456:old-test-token' && PartnerBotEntry::forPartner($partner, $host) === $link, 'A failed replacement preserves the existing CTA');

    Http::$setOk = true;
    $newLink = 'https://t.me/SecondPartnerBot';
    check($controller->setWebhook(new Request('777777:replacement'))['body']['bot_url'] === $newLink, 'A successful replacement returns its new public link');
    check(PartnerBotEntry::forPartner($partner, $host) === $newLink, 'The dashboard gets the new verified link');
    check($controller->removeWebhook()['status'] === 200 && PartnerBotEntry::forPartner($partner, $host) === null, 'Removing the webhook hides the CTA immediately');
    echo "PASS: representative bot onboarding, legacy verification, retry and setup changes.\n";
}
