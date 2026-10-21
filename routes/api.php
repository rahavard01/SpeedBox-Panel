<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\PanelController;
use App\Http\Middleware\CheckTokenExpiration;
use App\Http\Controllers\Api\V2UserController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Middleware\CheckUserBanned;
use App\Http\Controllers\Api\TariffsController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\Admin\WalletAdminController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\PanelRepsController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\Admin\AnnouncementAdminController;
use App\Http\Controllers\Api\Admin\TutorialAdminController;
use App\Http\Controllers\Api\TutorialController;
use App\Http\Controllers\Api\Admin\PartnersAdminController;
use App\Http\Controllers\Api\Admin\TariffsAdminController;
use App\Http\Controllers\Api\Admin\CardAdminController;
use App\Http\Controllers\Api\Admin\CommissionAdminController;
use App\Http\Controllers\Api\Admin\BotAdminController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Admin\DashboardAdminController;
use App\Http\Controllers\Api\DashboardPanelController;
use App\Http\Controllers\AuthLinkController;
use App\Http\Controllers\Api\UserBotSettingsController;
use App\Http\Controllers\Api\UserBotWebhookController;
use App\Http\Controllers\Api\UserBotSmsPaymentController;
use App\Http\Controllers\Api\UserBotSmsDeviceController;
use App\Http\Controllers\Api\UserBotSmsEventController;
use App\Http\Controllers\Api\BotBroadcastController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\Admin\SupportTicketAdminController;
use App\Http\Controllers\Api\Admin\PaymentSettingsAdminController;
use App\Http\Controllers\Api\Admin\InfrastructureController;
use App\Http\Controllers\Api\Node\MachineController as NodeMachineController;
use App\Http\Controllers\Api\Node\NodeController;
use App\Http\Controllers\Api\SubscriptionController;

/*
|--------------------------------------------------------------------------
| Public (no auth)
|--------------------------------------------------------------------------
*/
Route::post('/login', [LoginController::class, 'login']);
Route::get('/settings/link-parts', [SettingController::class, 'linkParts']);
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);
Route::post('/user-bot/webhook/{ownerType}/{ownerId}/{secret}', [UserBotWebhookController::class, 'handle']);
Route::post(
    '/user-bot/sms-payments/events',
    [UserBotSmsPaymentController::class, 'store']
)->middleware('throttle:60,1');
Route::post(
    '/user-bot/sms-payments/ping',
    [UserBotSmsDeviceController::class, 'ping']
)->middleware('throttle:30,1');
Route::get(
    '/user-bot/sms-payments/history',
    [UserBotSmsEventController::class, 'deviceHistory']
)->middleware('throttle:60,1');
Route::get('/ping', fn () => response('pong', 200));
Route::get('/v1/client/subscribe', [SubscriptionController::class, 'show']);

/*
|--------------------------------------------------------------------------
| SpeedBox node control plane (XBoard Node compatible)
|--------------------------------------------------------------------------
*/
Route::prefix('v2/server')->group(function () {
    Route::middleware('speedbox.node')->group(function () {
        Route::match(['get', 'post'], '/handshake', [NodeController::class, 'handshake']);
        Route::get('/config', [NodeController::class, 'config']);
        Route::get('/user', [NodeController::class, 'users']);
        Route::post('/report', [NodeController::class, 'report']);
    });
    Route::post('/connections/lease', [NodeController::class, 'connectionLease'])
        ->withoutMiddleware('throttle:api')
        ->middleware(\App\Http\Middleware\AuthenticateConnectionLease::class);
    Route::post('/machine/nodes', [NodeMachineController::class, 'nodes']);
    Route::post('/machine/status', [NodeMachineController::class, 'status']);
});
// آخرین نسخه SPEEDBOX برای ویندوز (public)


// آخرین نسخه SPEEDBOX برای اندروید (public)



/*
|--------------------------------------------------------------------------
| Password Reset (PUBLIC, throttled)  ← اینجا عمومی است
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->middleware('throttle:10,1')->group(function () {
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot']);
    Route::get('/reset-token/verify', [PasswordResetController::class, 'verifyToken']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);
});

/*
|--------------------------------------------------------------------------
| Protected (auth + token not expired + not banned)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', CheckTokenExpiration::class, CheckUserBanned::class])->group(function () {
    // --- Panel / Users ---
    Route::get('/user/panel', [PanelController::class, 'getProfile']);
    Route::get('/user/wallet', [PanelController::class, 'getWallet']);
    Route::get('/users', [PanelController::class, 'listUsers']);
    Route::get('/users/traffic-records', [PanelController::class, 'trafficRecords']);
    Route::get('/users/transaction-records', [PanelController::class, 'transactionRecords']);
    Route::get('/users/by-token', [PanelController::class, 'findByToken']);
    Route::get('/panel/plans', [V2UserController::class, 'plans']);
    Route::post('/panel/users/create', [V2UserController::class, 'create']);
    Route::get('/users/by-email', [PanelController::class, 'findByEmail']);
    Route::post('/users/online', [\App\Http\Controllers\Api\PartnerAccountReadController::class, 'online']);
    Route::get('/users/{id}/details', [\App\Http\Controllers\Api\PartnerAccountReadController::class, 'details'])->whereNumber('id');
    Route::get('/users/{id}/reset-history', [\App\Http\Controllers\Api\PartnerAccountReadController::class, 'resetHistory'])->whereNumber('id');
    Route::post('/users/ban', [PanelController::class, 'updateBan']);
    Route::post('/users/extend', [V2UserController::class, 'extend']);
    Route::post('/users/topup', [V2UserController::class, 'topup']);
    Route::post('/users/change-password', [PanelController::class, 'changePassword']);
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/panel/dashboard/cards', [DashboardPanelController::class, 'cards']);
    Route::get('/panel/dashboard/online', [\App\Http\Controllers\Api\PartnerAccountReadController::class, 'onlineSummary']);
    Route::get('/panel/dashboard/details', [DashboardPanelController::class, 'details']);
    Route::get('/panel/dashboard/monthly-trend', [DashboardPanelController::class, 'monthlyTrend']);

    // === Support Tickets (User) ===
    Route::get('/tickets', [SupportTicketController::class, 'index']);
    Route::post('/tickets', [SupportTicketController::class, 'store']);
    Route::get('/tickets/unread/count', [SupportTicketController::class, 'unreadCount']);
    Route::get('/tickets/attachments/{id}', [SupportTicketController::class, 'attachment'])->whereNumber('id');
    Route::get('/tickets/{id}', [SupportTicketController::class, 'show'])->whereNumber('id');
    Route::post('/tickets/{id}/reply', [SupportTicketController::class, 'reply'])->whereNumber('id');
    Route::post('/tickets/{id}/close', [SupportTicketController::class, 'close'])->whereNumber('id');

    Route::get('/user-bot/settings', [UserBotSettingsController::class, 'show']);
    Route::put('/user-bot/settings', [UserBotSettingsController::class, 'update']);
    Route::patch('/user-bot/settings/toggle', [UserBotSettingsController::class, 'toggleSetting']);
    Route::patch('/user-bot/settings/plans/{planKey}/toggle', [UserBotSettingsController::class, 'togglePlan']);    
    Route::post('/user-bot/set-webhook', [UserBotSettingsController::class, 'setWebhook']);
    Route::post('/user-bot/delete-webhook', [UserBotSettingsController::class, 'deleteWebhook']);
    Route::post('/user-bot/regenerate-owner-code', [UserBotSettingsController::class, 'regenerateOwnerCode']);   
    Route::get(
        '/user-bot/sms-devices',
        [UserBotSmsDeviceController::class, 'index']
    );

    Route::get(
        '/user-bot/sms-events',
        [UserBotSmsEventController::class, 'index']
    );

    Route::post(
        '/user-bot/sms-devices',
        [UserBotSmsDeviceController::class, 'store']
    );

    Route::patch(
        '/user-bot/sms-devices/{deviceId}',
        [UserBotSmsDeviceController::class, 'update']
    )->whereNumber('deviceId');

    Route::post(
        '/user-bot/sms-devices/{deviceId}/regenerate-token',
        [UserBotSmsDeviceController::class, 'regenerateToken']
    )->whereNumber('deviceId');

    Route::delete(
        '/user-bot/sms-devices/{deviceId}/permanent',
        [UserBotSmsDeviceController::class, 'destroy']
    )->whereNumber('deviceId');

    Route::delete(
        '/user-bot/sms-devices/{deviceId}',
        [UserBotSmsDeviceController::class, 'disconnect']
    )->whereNumber('deviceId');  

    Route::post('/user-bot/broadcast', [BotBroadcastController::class, 'userBot']);

    // لیست نمایندگان من (approved | pending | rejected | all)
    Route::get('/panel/my-reps', [PanelRepsController::class, 'index']);

    // --- Tariffs ---
    Route::get('/tariffs', [TariffsController::class, 'show']);

    // --- Wallet ---
    Route::get('/wallet/payment-methods', [WalletController::class, 'paymentMethods']);
    Route::get('/wallet/card', [WalletController::class, 'cardInfo']);
    Route::post('/wallet/card/receipt',  [WalletController::class, 'uploadReceipt']);
    Route::post('/wallet/card/submit',   [WalletController::class, 'submitCardDeposit']);
    Route::post('/wallet/card/topups', [WalletController::class, 'createCardTopup']);
    Route::get('/wallet/card/topups/active', [WalletController::class, 'activeCardTopup']);
    Route::get('/wallet/card/topups/{topup}/status', [WalletController::class, 'cardTopupStatus'])->whereNumber('topup');
    Route::post('/wallet/card/topups/{topup}/cancel', [WalletController::class, 'cancelCardTopup'])->whereNumber('topup');
    Route::get(
        '/wallet/card/notifications/pending',
        [WalletController::class, 'pendingCardTopupToasts']
    );

    Route::post(
        '/wallet/card/notifications/{id}/ack',
        [WalletController::class, 'ackCardTopupToast']
    )->whereNumber('id');
    Route::post('/wallet/crypto/topups', [WalletController::class, 'createCryptoTopup']);
    Route::get('/wallet/crypto/topups/active', [WalletController::class, 'activeCryptoTopup']);
    Route::post('/wallet/crypto/topups/{topup}/paid', [WalletController::class, 'markCryptoTopupPaid']);
    Route::get('/wallet/crypto/topups/{topup}/status', [WalletController::class, 'cryptoTopupStatus']);
    Route::get('/wallet/crypto/notifications/pending', [WalletController::class, 'pendingCryptoTopupToasts']);
    Route::post('/wallet/crypto/notifications/{id}/ack', [WalletController::class, 'ackCryptoTopupToast'])->whereNumber('id');
    Route::post('/wallet/crypto/topups/{topup}/cancel', [WalletController::class, 'cancelCryptoTopup']);
    Route::get ('/wallet/receipt/notifications/pending', [WalletController::class, 'pendingReceiptToasts']);
    Route::post('/wallet/receipt/notifications/{id}/ack', [WalletController::class, 'ackReceiptToast'])->whereNumber('id');
    Route::get('/user/transactions', [BillingController::class, 'self']);
    Route::get ('/wallet/adjust/notifications/pending', [WalletController::class, 'pendingAdjustToasts']);
    Route::post('/wallet/adjust/notifications/{id}/ack', [WalletController::class, 'ackAdjustToast'])->whereNumber('id');
    // Commission toasts for referrer (panel notifications)
    Route::get ('/wallet/commission/notifications/pending', [WalletController::class, 'pendingCommissionToasts']);
    Route::post('/wallet/commission/notifications/{id}/ack', [WalletController::class, 'ackCommissionToast'])->whereNumber('id');

    // --- Referral (user-side) ---
    Route::get('/validate/email-available',      [ReferralController::class, 'emailAvailable']);
    Route::get('/validate/panel-code-available', [ReferralController::class, 'panelCodeAvailable']);
    Route::post('/referrals',                    [ReferralController::class, 'store']);
    Route::get ('/referrals/notifications/pending', [ReferralController::class, 'refToastPending']);
    Route::post('/referrals/notifications/{id}/ack', [ReferralController::class, 'refToastAck'])->whereNumber('id');

    // === Announcements (User) ===
    Route::get ('/announcements', [AnnouncementController::class, 'index']);
    Route::get ('/announcements/notifications/pending', [AnnouncementController::class, 'pending']);
    Route::post('/announcements/notifications/{id}/ack', [AnnouncementController::class, 'ack'])->whereNumber('id');
    Route::post('/announcements/{id}/read', [AnnouncementController::class, 'read'])->whereNumber('id');
    Route::get('/announcements/unread/count', [AnnouncementController::class, 'unreadCount']);

    // === Tutorials (User list & detail) ===
    Route::get ('/tutorials',      [TutorialController::class, 'index']);   // لیست منتشرشده‌ها با فیلتر
    Route::get ('/tutorials/{id}', [TutorialController::class, 'show'])->whereNumber('id'); // جزئیات
    Route::get ('/tutorials/notifications/pending', [TutorialController::class, 'pendingToasts']);
    Route::post('/tutorials/notifications/{id}/ack', [TutorialController::class, 'ackToast'])->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Admin (همون URIهایی که فرانت الان مصرف می‌کنه)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['can:admin'])->prefix('admin')->group(function () {

        // شمارنده بج
        Route::get('/referrals/count', [ReferralController::class, 'adminCount']);

        // لیست
        Route::get('/referrals', [ReferralController::class, 'adminIndex']);

        // ترتیب مهم: ثابت‌ها قبل از پارامتری‌ها
        Route::get ('/referrals/notifications/pending', [ReferralController::class, 'adminToastPending']);
        Route::post('/referrals/notifications/{id}/ack', [ReferralController::class, 'adminToastAck'])->whereNumber('id');

        // جزئیات آیتم
        Route::get('/referrals/{id}', [ReferralController::class, 'adminShow'])->whereNumber('id');

        // اکشن‌ها
        Route::post('/referrals/{id}/approve', [ReferralController::class, 'adminApprove'])->whereNumber('id');
        Route::post('/referrals/{id}/reject',  [ReferralController::class, 'adminReject'])->whereNumber('id');

        // --- Tariffs Admin ---
        Route::get   ('/tariffs',                    [TariffsAdminController::class, 'index']);
        Route::put   ('/tariffs',                    [TariffsAdminController::class, 'update']);
        Route::delete('/tariff-groups/{id}',         [TariffsAdminController::class, 'destroyGroup'])
            ->whereNumber('id');

        Route::get('/tariff-groups/{id}/partners', [TariffsAdminController::class, 'partnersForGroup'])
            ->whereNumber('id');

        Route::put('/tariff-groups/{id}/partners', [TariffsAdminController::class, 'updatePartnersForGroup'])
            ->whereNumber('id');

        Route::patch ('/tariffs/{plan_key}/enable',  [TariffsAdminController::class, 'patchEnable'])
            ->where('plan_key', '^(gig|test|1m|3m|6m|12m)$');
        Route::post  ('/tariffs/percent-adjust',     [TariffsAdminController::class, 'percentAdjust']);

        Route::get('/user-bot/faqs', [\App\Http\Controllers\Api\Admin\UserBotFaqAdminController::class, 'index']);
        Route::put('/user-bot/faqs', [\App\Http\Controllers\Api\Admin\UserBotFaqAdminController::class, 'update']);

        // --- Card Settings Admin ---
        Route::get('/card-settings', [CardAdminController::class, 'index']);
        Route::put('/card-settings', [CardAdminController::class, 'update']);

        // --- Payment Settings Admin ---
        Route::get('/payment-settings', [PaymentSettingsAdminController::class, 'index']);
        Route::put('/payment-settings', [PaymentSettingsAdminController::class, 'update']);

        // --- Commission Settings Admin ---
        Route::get('/commission-settings', [CommissionAdminController::class, 'index']);
        Route::put('/commission-settings', [CommissionAdminController::class, 'update']);

        Route::get('/users',           [UserAdminController::class, 'index']);
        Route::post('/users/online', [\App\Http\Controllers\Api\Admin\AccountOperationsController::class, 'online']);
        Route::get('/users/{id}/details', [\App\Http\Controllers\Api\Admin\AccountOperationsController::class, 'show'])->whereNumber('id');
        Route::patch('/users/{id}', [\App\Http\Controllers\Api\Admin\AccountOperationsController::class, 'update'])->whereNumber('id');
        Route::post('/users/{id}/reset-secret', [\App\Http\Controllers\Api\Admin\AccountOperationsController::class, 'resetSecret'])->whereNumber('id');
        Route::get('/users/{id}/reset-history', [\App\Http\Controllers\Api\Admin\AccountOperationsController::class, 'resetHistory'])->whereNumber('id');
        Route::post('/users/{id}/reset-traffic', [\App\Http\Controllers\Api\Admin\AccountOperationsController::class, 'resetTraffic'])->whereNumber('id');
        Route::delete('/users/{id}', [\App\Http\Controllers\Api\Admin\AccountOperationsController::class, 'destroy'])->whereNumber('id');

        Route::get('/users/traffic-records', [UserAdminController::class, 'trafficRecords']);
        Route::get('/users/transaction-records', [UserAdminController::class, 'transactionRecords']);
        Route::post('/users/create',   [UserAdminController::class, 'create']);
        Route::post('/users/extend',   [UserAdminController::class, 'extend']);
        Route::post('/users/topup',    [UserAdminController::class, 'topup']);

        // === Client App Releases (Admin) ===

        // === Unified infrastructure: managed servers, machines and nodes ===
        Route::prefix('infrastructure')->group(function () {
            Route::get('/network-tunnels/capabilities', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'capabilities']);
            Route::post('/network-tunnels', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'store']);
            Route::put('/network-tunnels/{tunnel}', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'update'])->whereNumber('tunnel');
            Route::post('/network-tunnels/{tunnel}/actions', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'action'])->whereNumber('tunnel');
            Route::get('/network-tunnels/{tunnel}/export', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'export'])->whereNumber('tunnel');
            Route::get('/network-tunnels/{tunnel}/samples', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'samples'])->whereNumber('tunnel');
            Route::post('/network-tunnels/profiles', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'profiles']);
            Route::get('/tunnels', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'index']);
            Route::post('/network-tunnel-tests', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'test'])->middleware('throttle:5,1');
            Route::get('/network-tunnel-tests/{tunnelTest}', [\App\Http\Controllers\Api\Admin\NetworkTunnelController::class, 'testShow'])->whereNumber('tunnelTest');
            Route::post('/tunnel-tests', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'test'])->middleware('throttle:5,1');
            Route::get('/tunnel-tests/{tunnelTest}', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'testShow'])->whereNumber('tunnelTest');
            Route::post('/tunnels', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'store']);
            Route::put('/tunnels/{tunnel}', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'update'])->whereNumber('tunnel');
            Route::get('/tunnels/{tunnel}/tests', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'testHistory'])->whereNumber('tunnel');
            Route::post('/tunnels/{tunnel}/actions', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'action'])->whereNumber('tunnel');
            Route::get('/tunnel-operations/{tunnelOperation}', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'operation'])->whereNumber('tunnelOperation');
            Route::post('/tunnel-operations/{tunnelOperation}/cancel', [\App\Http\Controllers\Api\Admin\TunnelController::class, 'cancel'])->whereNumber('tunnelOperation');

            Route::get('/emergency-configs', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'index']);
            Route::post('/emergency-configs', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'store']);
            Route::put('/emergency-configs/settings', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'settings']);
            Route::put('/emergency-configs/order', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'order']);
            Route::get('/emergency-configs/{emergencyConfig}', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'show'])->whereNumber('emergencyConfig');
            Route::put('/emergency-configs/{emergencyConfig}', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'update'])->whereNumber('emergencyConfig');
            Route::patch('/emergency-configs/{emergencyConfig}/flags', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'flags'])->whereNumber('emergencyConfig');
            Route::delete('/emergency-configs/{emergencyConfig}', [\App\Http\Controllers\Api\Admin\EmergencyConfigController::class, 'destroy'])->whereNumber('emergencyConfig');
            Route::get('/configuration/templates', [\App\Http\Controllers\Api\Admin\SystemConfigurationController::class, 'templates']);
            Route::put('/configuration/templates/{name}', [\App\Http\Controllers\Api\Admin\SystemConfigurationController::class, 'saveTemplate'])->whereIn('name', ['singbox', 'clash', 'clashmeta', 'stash', 'surge', 'surfboard']);
            Route::delete('/configuration/templates/{name}', [\App\Http\Controllers\Api\Admin\SystemConfigurationController::class, 'resetTemplate'])->whereIn('name', ['singbox', 'clash', 'clashmeta', 'stash', 'surge', 'surfboard']);
            Route::get('/configuration/{section}', [\App\Http\Controllers\Api\Admin\SystemConfigurationController::class, 'show'])->whereIn('section', ['general', 'site', 'security', 'subscribe', 'server']);
            Route::put('/configuration/{section}', [\App\Http\Controllers\Api\Admin\SystemConfigurationController::class, 'update'])->whereIn('section', ['general', 'site', 'security', 'subscribe', 'server']);
            Route::get('/overview', [InfrastructureController::class, 'overview']);
            Route::get('/traffic-rank', [\App\Http\Controllers\Api\Admin\InfrastructureDashboardController::class, 'trafficRank']);
            Route::get('/queue', [\App\Http\Controllers\Api\Admin\InfrastructureDashboardController::class, 'queue']);
            Route::get('/queue/failed-jobs', [\App\Http\Controllers\Api\Admin\InfrastructureDashboardController::class, 'failedJobs']);
            Route::get('/servers', [InfrastructureController::class, 'servers']);
            Route::post('/servers', [InfrastructureController::class, 'storeServer']);
            Route::put('/servers/{managedServer}', [InfrastructureController::class, 'updateServer']);
            Route::delete('/servers/{managedServer}', [InfrastructureController::class, 'deleteServer']);
            Route::post('/servers/{managedServer}/deploy', [InfrastructureController::class, 'deploy']);
            Route::get('/servers/{managedServer}/deployments', [InfrastructureController::class, 'deployments']);

            Route::get('/machines', [InfrastructureController::class, 'machines']);
            Route::get('/machines/{machine}/warp', [\App\Http\Controllers\Api\Admin\MachineWarpController::class, 'show'])->whereNumber('machine');
            Route::post('/machines/{machine}/warp', [\App\Http\Controllers\Api\Admin\MachineWarpController::class, 'store'])->whereNumber('machine')->middleware('throttle:10,1');
            Route::post('/machines/{machine}/rotate-token', [InfrastructureController::class, 'rotateMachineToken']);
            Route::get('/machines/{machine}/history', [InfrastructureController::class, 'machineHistory']);

            Route::get('/nodes', [InfrastructureController::class, 'nodes']);
            Route::post('/keys/reality', [InfrastructureController::class, 'realityKeys']);
            Route::post('/keys/ech', [InfrastructureController::class, 'generateEchKey']);
            Route::post('/keys/vless-encryption', [InfrastructureController::class, 'generateVlessEncryption'])->middleware('throttle:10,1');
            Route::post('/nodes/certificate-fingerprint', [InfrastructureController::class, 'certificateFingerprint'])->middleware('throttle:10,1');
            Route::put('/nodes/order', [InfrastructureController::class, 'sortNodes']);
            Route::patch('/nodes/{node}/flags', [InfrastructureController::class, 'nodeFlags']);
            Route::post('/nodes', [InfrastructureController::class, 'storeNode']);
            Route::put('/nodes/{node}', [InfrastructureController::class, 'updateNode']);
            Route::post('/nodes/{node}/clone', [InfrastructureController::class, 'cloneNode']);
            Route::delete('/nodes/{node}', [InfrastructureController::class, 'deleteNode']);

            Route::get('/plans', [InfrastructureController::class, 'plans']);
            Route::post('/plans', [InfrastructureController::class, 'savePlan']);
            Route::put('/plans/{plan}', [InfrastructureController::class, 'savePlan'])->whereNumber('plan');
            Route::delete('/plans/{plan}', [InfrastructureController::class, 'deletePlan'])->whereNumber('plan');

            Route::get('/groups', [InfrastructureController::class, 'groups']);
            Route::post('/groups', [InfrastructureController::class, 'saveGroup']);
            Route::put('/groups/{group}', [InfrastructureController::class, 'saveGroup']);
            Route::delete('/groups/{group}', [InfrastructureController::class, 'deleteGroup']);

            Route::get('/routes', [InfrastructureController::class, 'routes']);
            Route::post('/routes', [InfrastructureController::class, 'saveRoute']);
            Route::put('/routes/{serverRoute}', [InfrastructureController::class, 'saveRoute']);
            Route::delete('/routes/{serverRoute}', [InfrastructureController::class, 'deleteRoute']);

            Route::get('/settings', [InfrastructureController::class, 'settings']);
            Route::put('/settings', [InfrastructureController::class, 'updateSettings']);
        });

    });
    /*
    |--------------------------------------------------------------------------
    | Wallet Admin (با can:admin)
    |--------------------------------------------------------------------------
    */
    Route::middleware(['can:admin'])->prefix('admin')->group(function () {
        // شمارش واریزی‌های در انتظار (برای بج منو/همبرگر)
        Route::get('/wallet/receipts/count', [WalletAdminController::class, 'countPending']);

        // لیست واریزی‌ها (status: pending|verified|rejected|all)
        Route::get('/wallet/receipts', [WalletAdminController::class, 'index']);

        Route::get('/wallet/receipts/{id}/file', [WalletAdminController::class, 'showFile'])->whereNumber('id');

        // جزئیات یک رسید
        Route::get('/wallet/receipts/{id}', [WalletAdminController::class, 'show'])->whereNumber('id');

        // تأیید رسید
        Route::post('/wallet/receipts/{id}/verify', [WalletAdminController::class, 'verify'])->whereNumber('id');

        // رد رسید
        Route::post('/wallet/receipts/{id}/reject',  [WalletAdminController::class, 'reject'])->whereNumber('id');

        // === Support Tickets (Admin) ===
        Route::get('/tickets/count', [SupportTicketAdminController::class, 'count']);
        Route::get('/tickets/attachments/{id}', [SupportTicketAdminController::class, 'attachment'])->whereNumber('id');
        Route::get('/tickets', [SupportTicketAdminController::class, 'index']);
        Route::get('/tickets/{id}', [SupportTicketAdminController::class, 'show'])->whereNumber('id');
        Route::post('/tickets/{id}/reply', [SupportTicketAdminController::class, 'reply'])->whereNumber('id');
        Route::post('/tickets/{id}/close', [SupportTicketAdminController::class, 'close'])->whereNumber('id');
        Route::post('/tickets/{id}/reopen', [SupportTicketAdminController::class, 'reopen'])->whereNumber('id');

        // === Announcements (Admin) ===
        Route::get   ('/announcements',             [AnnouncementAdminController::class, 'index']);
        Route::post  ('/announcements',             [AnnouncementAdminController::class, 'store']);
        Route::patch ('/announcements/{id}',        [AnnouncementAdminController::class, 'update'])->whereNumber('id');
        Route::post  ('/announcements/{id}/toggle', [AnnouncementAdminController::class, 'toggle'])->whereNumber('id');
        Route::delete('/announcements/{id}',        [AnnouncementAdminController::class, 'destroy'])->whereNumber('id');

        // === Tutorials (Admin) ===
        Route::get   ('/tutorials',      [TutorialAdminController::class, 'index']);
        Route::post  ('/tutorials',      [TutorialAdminController::class, 'store']);
        Route::patch ('/tutorials/{id}', [TutorialAdminController::class, 'update'])->whereNumber('id');
        Route::delete('/tutorials/{id}', [TutorialAdminController::class, 'destroy'])->whereNumber('id');

        // === Partners (Admin) ===
        Route::get('/partners/account-statistics', \App\Http\Controllers\Api\Admin\PartnerAccountStatisticsController::class);
        Route::get   ('/partners',                 [PartnersAdminController::class, 'index']);
        Route::post  ('/partners',                 [PartnersAdminController::class, 'store']); 
        Route::delete('/partners/{id}',            [PartnersAdminController::class, 'destroy']);
        Route::patch ('/partners/{id}/profile',    [PartnersAdminController::class, 'updateProfile']);
        Route::get   ('/partners/{id}/pricing-preview', [PartnersAdminController::class, 'pricingPreview'])->whereNumber('id');
        Route::patch ('/partners/{id}/pricing',    [PartnersAdminController::class, 'updatePricing'])->whereNumber('id');
        Route::get   ('/partners/{id}/status-flags', [PartnersAdminController::class, 'statusFlags'])->whereNumber('id');
        Route::patch ('/partners/{id}/ban',        [PartnersAdminController::class, 'banPartner'])->whereNumber('id');
        Route::patch ('/partners/{id}/ban-users',  [PartnersAdminController::class, 'banPartnerUsers'])->whereNumber('id');
        Route::post  ('/partners/{id}/wallet-adjust', [PartnersAdminController::class, 'walletAdjust'])->whereNumber('id');

        Route::post('/bot/set-webhook',    [BotAdminController::class, 'setWebhook']);
        Route::post('/bot/remove-webhook', [BotAdminController::class, 'removeWebhook']);
        Route::get('/bot/status', [BotAdminController::class, 'status']);
        Route::post('/bot/broadcast', [BotBroadcastController::class, 'partnersBot']);
        
        // === Billing (Admin) — فهرست همه‌ی صورتحساب‌ها برای پنل ادمین ===
        Route::get('/transactions', [BillingController::class, 'all']);
        // === Dashboard (Admin) 
        Route::get('/dashboard/cards', [DashboardAdminController::class, 'cards']);
        Route::get('/dashboard/details', [DashboardAdminController::class, 'details']);
        Route::get('/dashboard/monthly-trend', [DashboardAdminController::class, 'monthlyTrend']);

    });
});

Route::get('/auth/validate-link', [AuthLinkController::class, 'validateLink']);



