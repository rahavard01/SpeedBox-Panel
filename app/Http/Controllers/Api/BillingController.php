<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    /**
     * GET /api/user/transactions
     * فقط تراکنش‌های همان کاربر لاگین‌شده را برمی‌گرداند.
     */
    public function self(Request $request)
    {
        $user = $request->user(); // مدل User روی panel_users مپ شده
        if (!$user) {
            return response()->json([], 401);
        }

        // فقط رکوردهای همین کاربر
        $rows = DB::table('panel_transactions as t')
            ->where('t.panel_user_id', $user->id)
            ->select([
                't.id',
                't.created_at',
                't.type',
                't.direction',          // 'credit' یا 'debit' (گاهی 'in'/'out')
                't.amount',             // decimal → تبدیل می‌کنیم
                't.balance_after',      // decimal → تبدیل می‌کنیم
                't.status',
                't.performed_by_role',
                't.meta',               // JSON: ممکن است usernames داخلش باشد
                't.plan_key_after',
            ])
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->get();

        // قبل از map، تمام user_ids داخل meta را جمع می‌کنیم تا یکجا رزولوشن کنیم
        $allIds = [];
        foreach ($rows as $r) {
            if (!empty($r->meta)) {
                try {
                    $meta = is_string($r->meta) ? json_decode($r->meta, true) : $r->meta;
                    if (is_array($meta)) {
                        // فعلاً فقط user_ids برای انواع غیرکمیسیون
                        if (!empty($meta['user_ids'])) {
                            $ids = $meta['user_ids'];
                            if (!is_array($ids)) $ids = [$ids];
                            foreach ($ids as $idVal) {
                                $idInt = (int) $idVal;
                                if ($idInt > 0) $allIds[] = $idInt;
                            }
                        }
                    }
                } catch (\Throwable $e) { /* ignore */ }
            }
        }
        $allIds = array_values(array_unique($allIds));

        // نقشه‌ی id -> email از جدول v2_user (برای انواع غیرِ کمیسیون)
        $idToUsername = [];
        if (!empty($allIds)) {
            $idToUsername = DB::table('v2_user')
                ->whereIn('id', $allIds)
                ->pluck('email', 'id')
                ->toArray();
        }

        // خروجی مطابق انتظارات فرانت
        $out = $rows->map(function ($r) use ($user, $idToUsername) {

            // علامت مبلغ بر اساس جهت تراکنش
            $base = (int) $r->amount;
            $dir  = strtolower((string) $r->direction);
            $signed = (in_array($dir, ['debit','out','minus'], true)) ? -$base : +$base;

            // استخراج meta برای ساخت affected_users
            $affected_users = [];
            $meta = [];
            if (!empty($r->meta)) {
                try {
                    $meta = is_string($r->meta) ? json_decode($r->meta, true) : $r->meta;
                    if (!is_array($meta)) $meta = [];
                } catch (\Throwable $e) {
                    $meta = [];
                }
            }

            if (($r->type ?? '') === 'referrer_commission') {
                // ✅ فقط برای کمیسیون معرّف: از کُدِ پنل داخل متا استفاده کن
                $code = $meta['source_user_code'] ?? null;
                if (is_string($code) && $code !== '') {
                    $affected_users = [$code];
                } else {
                    $affected_users = []; // اگر به هر دلیل کد نبود، خالی بگذار
                }
            } else {
                // حالت‌های غیرکمیسیون: مثل قبل از v2_user ایمیل را بر اساس user_ids بساز
                if (!empty($meta['user_ids'])) {
                    $raw = $meta['user_ids'];
                    if (!is_array($raw)) $raw = [$raw];
                    foreach ($raw as $item) {
                        if (is_numeric($item)) {
                            $id = (int) $item;
                            $label = $idToUsername[$id] ?? (string) $id;
                            if ($label !== '') $affected_users[] = $label;
                        } else {
                            $label = (string) $item;
                            if ($label !== '') $affected_users[] = $label;
                        }
                    }
                    $affected_users = array_values(array_unique($affected_users));
                }
            }

            $planMap = [
                'test'      => 'تست',
                '1m'        => 'یک ماهه',
                '1m_noexp'  => 'یک ماهه (بدون انقضا)',
                '3m'        => 'سه ماهه',
                '6m'        => 'شش ماهه',
                '12m'       => 'یک‌ساله',
            ];

            // از ستون یا از متا بخوان (هر کدام موجود بود)
            $planKeyRaw = $r->plan_key_after
                ?? ($meta['plan_key_after'] ?? ($meta['plan_key'] ?? null));

            // فقط برای خرید/تمدید نمایش بده
            $txnType  = strtolower((string)($r->type ?? ''));
            $showPlan = in_array($txnType, [
                'account_purchase','buy_account','purchase',
                'account_extend','extend_account','renew','renewal',
            ], true);

            $planKey   = ($showPlan && $planKeyRaw) ? (string)$planKeyRaw : null;
            $planLabel = $planKey ? ($planMap[$planKey] ?? $planKey) : null;

            // (اختیاری) تاریخ‌های قبل/بعد تمدید اگر در meta ذخیره شده
            $expiredBefore = $meta['expired_before'] ?? null;
            $expiredAfter  = $meta['expired_after']  ?? null;            
            // --- حجم ترافیک خریداری‌شده (برای «X گیگ ترافیک اضافه») ---
            $trafficGb = null;
            // در topup، ما quantity را به صورت "5GB" گذاشته‌ایم
            if (!empty($meta['quantity'])) {
                if (is_string($meta['quantity']) && preg_match('/(\d+)\s*GB/i', $meta['quantity'], $m)) {
                    $trafficGb = (int) $m[1];
                } elseif (is_numeric($meta['quantity'])) {
                    $trafficGb = (int) $meta['quantity'];
                }
            } elseif (isset($meta['gb'])) {
                // فالبک اگر به‌صورت عدد خالی آمده باشد
                $trafficGb = (int) $meta['gb'];
            }    
            
            elseif (isset($meta['volume_gb'])) {
                $trafficGb = (int) $meta['volume_gb'];
            }

            return [
                'id'                => $r->id,
                'created_at'        => $r->created_at,
                'type'              => $r->type ?? '-',
                'amount'            => $signed,                          // با علامت
                'signed_amount'     => $signed,                          // برای کارت موبایل صورت‌حساب
                'balance_after'     => (int) $r->balance_after,
                'status'            => $r->status ?? 'unknown',
                'performed_by_role' => \App\Services\TransactionOperator::displayRole($r->performed_by_role ?? null, $meta),
                'meta'              => [
                    'operator_source' => $meta['operator_source'] ?? null,
                ],
                'user_code'         => $user->code,                      // برای شارژ کیف پول
                'username'          => $user->email,                     // fallback اگر meta خالی بود
                'affected_users'    => $affected_users,                  // نام کاربری/پنل برای نمایش
                'plan_key'          => $planKey,       // مثل '1m'، '3m'، ...
                'plan_label'        => $planLabel,     // مثل «یک ماهه»
                'tariff_group_id'   => $meta['tariff_group_id'] ?? null,
                'tariff_group_name' => $meta['tariff_group_name'] ?? null,                
                'expired_before'    => $expiredBefore, // اختیاری برای تمدید
                'expired_after'     => $expiredAfter,  // اختیاری برای تمدید
                'traffic_gb'        => $trafficGb,
                'crypto_asset'      => $meta['crypto_asset'] ?? ($meta['asset'] ?? null),
                'crypto_network'    => $meta['crypto_network'] ?? ($meta['network'] ?? null),
                'crypto_amount'     => $meta['crypto_amount'] ?? null,
                'crypto_tx_hash'    => $meta['crypto_tx_hash'] ?? ($meta['tx_hash'] ?? null),
                'payment_method'    => $meta['payment_method'] ?? ($meta['method'] ?? null),
            ];
        });

        return response()->json($out);
    }

    public function all(Request $request)
    {
        $panelCode = trim((string)$request->query('panel_code', ''));

        $rows = DB::table('panel_transactions as t')
            ->leftJoin('panel_users as pu', 'pu.id', '=', 't.panel_user_id')
            ->select([
                't.id',
                't.created_at',
                't.type',
                't.direction',
                't.amount',
                't.balance_after',
                't.status',
                't.performed_by_role',
                't.meta',
                't.plan_key_after',
                DB::raw('COALESCE(pu.code, "")  as owner_code'),
                DB::raw('COALESCE(pu.email, "") as owner_email'),
            ])
            ->when($panelCode !== '', function ($q) use ($panelCode) {
                $q->where('pu.code', $panelCode);
            })
            ->orderByDesc('t.created_at')
            ->orderByDesc('t.id')
            ->get();

        $allIds = [];
        foreach ($rows as $r) {
            if (!empty($r->meta)) {
                try {
                    $meta = is_string($r->meta) ? json_decode($r->meta, true) : $r->meta;
                    if (is_array($meta) && !empty($meta['user_ids'])) {
                        $ids = $meta['user_ids'];
                        if (!is_array($ids)) $ids = [$ids];
                        foreach ($ids as $idVal) {
                            $idInt = (int) $idVal;
                            if ($idInt > 0) $allIds[] = $idInt;
                        }
                    }
                } catch (\Throwable $e) {}
            }
        }

        $allIds = array_values(array_unique($allIds));
        $idToUsername = [];

        if (!empty($allIds)) {
            $idToUsername = DB::table('v2_user')
                ->whereIn('id', $allIds)
                ->pluck('email', 'id')
                ->toArray();
        }

        $out = $rows->map(function ($r) use ($idToUsername) {
            $base = (int) $r->amount;
            $dir  = strtolower((string) $r->direction);
            $signed = in_array($dir, ['debit','out','minus'], true) ? -$base : +$base;

            $meta = [];
            if (!empty($r->meta)) {
                try {
                    $meta = is_string($r->meta) ? json_decode($r->meta, true) : $r->meta;
                    if (!is_array($meta)) $meta = [];
                } catch (\Throwable $e) {
                    $meta = [];
                }
            }

            $affected_users = [];

            if (($r->type ?? '') === 'referrer_commission') {
                $code = $meta['source_user_code'] ?? null;
                if (is_string($code) && $code !== '') {
                    $affected_users = [$code];
                }
            } else {
                if (!empty($meta['user_ids'])) {
                    $raw = $meta['user_ids'];
                    if (!is_array($raw)) $raw = [$raw];

                    foreach ($raw as $item) {
                        if (is_numeric($item)) {
                            $id = (int) $item;
                            $label = $idToUsername[$id] ?? (string) $id;
                            if ($label !== '') $affected_users[] = $label;
                        } else {
                            $label = (string) $item;
                            if ($label !== '') $affected_users[] = $label;
                        }
                    }

                    $affected_users = array_values(array_unique($affected_users));
                }
            }

            $planMap = [
                'test'       => 'تست',
                '1m'         => 'یک ماهه',
                '1m_noexp'   => 'یک ماهه (بدون انقضا)',
                '3m'         => 'سه ماهه',
                '6m'         => 'شش ماهه',
                '12m'        => 'یک‌ساله',
                'gig'        => 'گیگ',
                'volume_gig' => 'حجمی',
            ];

            $planKeyRaw = $r->plan_key_after
                ?? ($meta['plan_key_after'] ?? ($meta['plan_key'] ?? null));

            if (is_string($planKeyRaw) && preg_match('/^\d+_(.+)$/', $planKeyRaw, $m)) {
                $planKeyRaw = $m[1];
            }

            $txnType  = strtolower((string)($r->type ?? ''));
            $showPlan = in_array($txnType, [
                'account_purchase','buy_account','purchase',
                'account_extend','extend_account','renew','renewal',
            ], true);

            $planKey   = ($showPlan && $planKeyRaw) ? (string)$planKeyRaw : null;
            $planLabel = $planKey ? ($planMap[$planKey] ?? $planKey) : null;

            $trafficGb = null;
            if (!empty($meta['quantity'])) {
                if (is_string($meta['quantity']) && preg_match('/(\d+)\s*GB/i', $meta['quantity'], $m)) {
                    $trafficGb = (int) $m[1];
                } elseif (is_numeric($meta['quantity'])) {
                    $trafficGb = (int) $meta['quantity'];
                }
            } elseif (isset($meta['gb'])) {
                $trafficGb = (int) $meta['gb'];
            } elseif (isset($meta['volume_gb'])) {
                $trafficGb = (int) $meta['volume_gb'];
            }

            return [
                'id'                => $r->id,
                'created_at'        => $r->created_at,
                'type'              => $r->type ?? '-',
                'amount'            => $signed,
                'direction'         => $r->direction,
                'signed_amount'     => $signed,
                'balance_after'     => (int) $r->balance_after,
                'status'            => $r->status ?? 'unknown',
                'performed_by_role' => \App\Services\TransactionOperator::displayRole($r->performed_by_role ?? null, $meta),
                'operator'          => \App\Services\TransactionOperator::displayRole($r->performed_by_role ?? null, $meta),
                'meta'              => $meta,
                'user_code'         => $r->owner_code ?? '',
                'username'          => $r->owner_email ?? '',
                'affected_users'    => $affected_users,
                'plan_key'          => $planKey,
                'plan_label'        => $planLabel,
                'tariff_group_id'   => $meta['tariff_group_id'] ?? null,
                'tariff_group_name' => $meta['tariff_group_name'] ?? null,
                'expired_before'    => $meta['expired_before'] ?? null,
                'expired_after'     => $meta['expired_after'] ?? null,
                'traffic_gb'        => $trafficGb,
                'crypto_asset'      => $meta['crypto_asset'] ?? ($meta['asset'] ?? null),
                'crypto_network'    => $meta['crypto_network'] ?? ($meta['network'] ?? null),
                'crypto_amount'     => $meta['crypto_amount'] ?? null,
                'crypto_tx_hash'    => $meta['crypto_tx_hash'] ?? ($meta['tx_hash'] ?? null),
                'payment_method'    => $meta['payment_method'] ?? ($meta['method'] ?? null),
            ];
        });

        return response()->json($out);
    }

}
