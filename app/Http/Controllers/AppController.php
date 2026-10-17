<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AppController extends Controller
{
    public function index(Request $request)
    {
        if (preg_match('#^(.+)/([A-Za-z0-9]{16,64})$#', $request->path(), $match) && Schema::hasTable('v2_settings')) {
            $prefix = trim((string) \App\Services\RuntimeSetting::get('subscribe_path', 's'), '/');
            if ($prefix !== '' && $match[1] === $prefix) {
                return app(\App\Http\Controllers\Api\SubscriptionController::class)->show($request, $match[2]);
            }
        }
        $user = Auth::user();

        // اگر لاگین نیست
        if (!$user) {
            $bootstrap = [
                'guest' => true,
                'user'  => null,
                'wallet'=> ['balance' => null],
                'pending_counts' => ['topups'=>0, 'referrals'=>0, 'commissions'=>0, 'announcements'=>0, 'tickets'=>0],
            ];
            return view('app', compact('bootstrap'));
        }

        // اطلاعات سبکِ نمایشی
        $userLite = [
            'id'    => $user->id,
            'name'  => (string)($user->name ?? ''),
            'email' => (string)($user->email ?? ''),
            'code'  => (string)($user->code ?? ''), // اگر ستونه توی panel_users هست
        ];

        // اعتبار کیف پول: طبق کدت در PanelController از خودِ مدل User فیلد credit استفاده می‌کنی
        $balance = (int)($user->credit ?? 0);

        // شمارنده‌های Pending
        $pendingTopups = 0;
        if (Schema::hasTable('panel_wallet_receipts')) {
            $pendingTopups = (int) DB::table('panel_wallet_receipts')
                ->where('status', 'pending')->count();
        }

        $pendingReferrals = 0;
        if (Schema::hasTable('panel_referrals')) {
            $pendingReferrals = (int) DB::table('panel_referrals')
                ->where('status', 'pending')->count();
        }

        // کمیسیون‌های در انتظار: رسیدهای تأیید شده‌ای که کمیسیون‌شان هنوز پرداخت نشده
        $pendingCommissions = 0;
        if (Schema::hasTable('panel_wallet_receipts')) {
            $pendingCommissions = (int) DB::table('panel_wallet_receipts')
                ->where('status', 'verified')
                ->where(function($q){
                    $q->whereNull('commission_paid')
                      ->orWhere('commission_paid', false);
                })
                ->count();
        }

        $partnerNotices = ['count' => 0, 'announcement_count' => 0];
        if ((int) ($user->role ?? 2) !== 1 && Schema::hasTable('support_ticket_messages')
            && Schema::hasTable('panel_announcements')) {
            $partnerNotices = app(\App\Services\PartnerNotificationState::class)->snapshot((int) $user->id);
        }

        $bootstrap = [
            'guest' => false,
            'user'  => $userLite,
            'wallet'=> ['balance' => $balance],
            'pending_counts' => [
                'topups'      => $pendingTopups,
                'referrals'   => $pendingReferrals,
                'commissions' => $pendingCommissions,
                'announcements' => $partnerNotices['announcement_count'],
                'tickets' => $partnerNotices['count'],
            ],
        ];

        return view('app', compact('bootstrap'));
    }
}
