<?php

// Read-only rollout check. Does not charge, reset or activate accounts.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB, Schema};
use App\Services\{RuntimeSetting, SubscriptionReservationService};
try {
    if (!Schema::hasTable('speedbox_subscription_reservations') || !Schema::hasColumn('v2_user', 'subscription_generation')) {
        throw new RuntimeException('Reservation migration is not applied.');
    }
    foreach (DB::table('v2_plan')->whereNotNull('group_id')->distinct()->pluck('group_id') as $groupId) {
        app(SubscriptionReservationService::class)->ensureGenerationSupport((object) ['group_id' => $groupId]);
    }
    echo 'RESERVATION RUNTIME READY'.PHP_EOL;
    echo 'Renewal mode: '.(int) RuntimeSetting::get('renew_order_event_id', 2).' (2=reserve/preserve, 3=reserve/reset, 1=instant/reset, 0=instant/preserve)'.PHP_EOL;
    echo 'Waiting reservations: '.DB::table('speedbox_subscription_reservations')->where('state', 'reserved')->count().PHP_EOL;
    echo 'Pending notifications: '.DB::table('speedbox_reservation_notifications')->whereNull('sent_at')->count().PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'RESERVATION RUNTIME NOT READY: '.$error->getMessage().PHP_EOL);
    exit(1);
}
