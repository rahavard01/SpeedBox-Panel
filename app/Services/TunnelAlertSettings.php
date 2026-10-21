<?php
namespace App\Services;

use App\Models\ServerTunnel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** One capability check for forms, validation and the background monitor. */
class TunnelAlertSettings
{
    public function capability(): array
    {
        if (!Schema::hasTable('speedbox_tunnel_alerts')) return ['available'=>false,'reason'=>'ابتدا به‌روزرسانی پایگاه دادهٔ پنل را اجرا کنید.'];
        if (!Schema::hasTable('panel_bot_settings') || !TelegramConfig::enabled()) {
            return ['available'=>false,'reason'=>'ابتدا ربات پنل/نمایندگان را در تنظیمات ربات فعال کنید.'];
        }
        if (!TelegramConfig::adminChatIds()) return ['available'=>false,'reason'=>'ابتدا با حساب ادمین وارد ربات پنل/نمایندگان شوید.'];
        return ['available'=>true,'reason'=>''];
    }

    public function resolve(Request $request, ?ServerTunnel $existing = null): bool
    {
        $request->validate(['telegram_alerts'=>'sometimes|boolean']);
        $current = (bool) ($existing?->settings['telegram_alerts'] ?? false);
        if (!$request->has('telegram_alerts')) return $existing ? $current : $this->capability()['available'];
        $wanted = $request->boolean('telegram_alerts');
        if ($wanted && !$current && !($capability = $this->capability())['available']) {
            throw ValidationException::withMessages(['telegram_alerts'=>$capability['reason']]);
        }
        return $wanted;
    }
}
