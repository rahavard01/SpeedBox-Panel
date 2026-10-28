<?php
namespace App\Jobs;

use App\Models\ServerTunnel;
use App\Services\{TelegramConfig,TunnelAlertSettings,TunnelOutageMonitor};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeEncrypted,ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{Cache,DB,Http,Log};
use Throwable;

class SendTunnelOutageAlert implements ShouldQueue,ShouldBeEncrypted
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public int $tries = 1;
    public int $timeout = 120;
    public function __construct(public readonly int $tunnelId, public readonly string $incident)
    {
        $this->onQueue('deployments');
    }

    public function handle(TunnelOutageMonitor $monitor): void
    {
        $lock = Cache::lock('speedbox-tunnel-alert-send-'.$this->tunnelId,150);
        if (!$lock->get()) return;
        try {
            if (!app(TunnelAlertSettings::class)->capability()['available']) return;
            $tunnel = ServerTunnel::with(['iran','foreign'])->find($this->tunnelId);
            $state = DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$this->tunnelId)->first();
            if (!$tunnel || !$state || $state->incident !== $this->incident || $state->failures < 2 || !$monitor->eligible($tunnel)) return;
            if ($monitor->quiet($tunnel,true) || !$state->observed_at || now()->diffInSeconds($state->observed_at,true) > 180) return;
            if (!\App\Services\TunnelHealth::fresh($tunnel)) return;
            $unreachable=\App\Services\TunnelHealth::unreachable($tunnel);
            if (!$unreachable && $monitor->health($tunnel) !== false) return;
            if ($state->next_delivery_at && now()->lt($state->next_delivery_at)) return;
            $delivered = json_decode($state->delivered_to ?? '[]',true) ?: [];
            $engine = ['backpack'=>'BackPack','gost'=>'GOST','gre'=>'GRE','wattunnel'=>'WatTunnel'][$tunnel->engine] ?? $tunnel->engine;
            $title=$unreachable?'🟠 عدم دسترسی به سرور تونل':'🔴 قطع ارتباط تونل';
            $note=$unreachable?'در دو بررسی پیاپی، دسترسی به سرور تأیید نشد؛ وضعیت تونل قابل بررسی نیست.':'ارتباط در دو بررسی پیاپی قطع بوده است.';
            if(!$unreachable&&\App\Services\TunnelHealth::reason($tunnel)==='target'){
                $title='🟠 سرویس مقصد تونل پاسخ نمی‌دهد';$note='در دو بررسی پیاپی، سرویس نود مقصد پاسخ نداده است؛ این نتیجه به معنی قطع حامل تونل نیست.';
            }
            $text = $title."\n\n🏷 {$tunnel->name}\n🔗 نوع: {$engine}\n🇮🇷 ایران: ".($tunnel->iran?->name ?? '—')
                ."\n🌐 خارج: ".($tunnel->foreign?->name ?? '—')."\n🕒 "."\u{2066}".\App\Support\JalaliDateFormatter::forTelegram(now(),'Y/m/d — H:i')."\u{2069}"
                ."\n\n⚠️ ".$note." جهت بررسی به پنل مراجعه کنید.";
            $url = rtrim((string) config('app.url'),'/').'/admin/infrastructure/tunnels';
            $keyboard = filter_var($url,FILTER_VALIDATE_URL) && str_starts_with($url,'https://')
                ? ['reply_markup'=>json_encode(['inline_keyboard'=>[[['text'=>'🔎 بررسی تونل‌ها','url'=>$url]]]],JSON_UNESCAPED_UNICODE)] : [];
            $failed = false;
            foreach (TelegramConfig::adminChatIds() as $chatId) {
                if (in_array($chatId,$delivered,true)) continue;
                // Each successful recipient is persisted before another is attempted.
                try {
                    $reply = Http::asForm()->timeout(12)->post('https://api.telegram.org/bot'.TelegramConfig::token().'/sendMessage',
                        ['chat_id'=>$chatId,'text'=>$text,'disable_web_page_preview'=>true,...$keyboard]);
                    if (!$reply->successful() || $reply->json('ok') !== true) { $failed=true; continue; }
                    $delivered[] = $chatId;
                    DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$tunnel->id)->where('incident',$this->incident)
                        ->update(['delivered_to'=>json_encode($delivered),'updated_at'=>now()]);
                } catch (Throwable) { $failed=true; }
            }
            DB::table('speedbox_tunnel_alerts')->where('tunnel_id',$tunnel->id)->where('incident',$this->incident)
                ->update(['next_delivery_at'=>now()->addMinutes($failed ? 5 : 1440),'updated_at'=>now()]);
            if ($failed) Log::warning('Tunnel outage notification will be retried.',['tunnel_id'=>$tunnel->id]);
        } finally { $lock->release(); }
    }
}
