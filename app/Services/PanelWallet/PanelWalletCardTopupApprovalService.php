<?php

namespace App\Services\PanelWallet;

use App\Models\PanelWalletCardTopup;
use App\Models\PanelWalletReceipt;
use App\Services\TelegramNotifier;
use App\Services\TransactionLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PanelWalletCardTopupApprovalService
{
    public function approve(
        int $topupId,
        string $source,
        ?int $actorId = null
    ): array {
        if (!in_array($source, ['sms', 'manual', 'ai'], true)) {
            throw new RuntimeException('منبع تأیید پرداخت معتبر نیست.');
        }

        return DB::transaction(function () use (
            $topupId,
            $source,
            $actorId
        ) {
            $topup = PanelWalletCardTopup::query()
                ->lockForUpdate()
                ->find($topupId);

            if (!$topup) {
                return [
                    'ok' => false,
                    'code' => 'NOT_FOUND',
                    'topup_id' => $topupId,
                ];
            }

            if ($topup->status === 'paid') {
                return [
                    'ok' => false,
                    'code' => 'ALREADY_PAID',
                    'topup_id' => (int) $topup->id,
                    'transaction_id' => $topup->transaction_id
                        ? (int) $topup->transaction_id
                        : null,
                ];
            }

            if (in_array($topup->status, ['cancelled', 'expired'], true)) {
                return [
                    'ok' => false,
                    'code' => strtoupper($topup->status),
                    'topup_id' => (int) $topup->id,
                ];
            }

            if ($topup->status !== 'pending') {
                return [
                    'ok' => false,
                    'code' => 'INVALID_STATE',
                    'topup_id' => (int) $topup->id,
                ];
            }

            $panelUserId = (int) $topup->user_id;
            $amount = (int) $topup->amount_irt;

            if ($panelUserId <= 0 || $amount <= 0) {
                throw new RuntimeException('اطلاعات پرداخت ناقص است.');
            }

            $receipt = PanelWalletReceipt::query()
                ->where('card_topup_id', $topup->id)
                ->where('user_id', $panelUserId)
                ->where('status', '!=', 'rejected')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            /*
             * اگر Receipt قبلاً توسط Admin/AI تأیید شده باشد،
             * موجودی را دوباره افزایش نمی‌دهیم.
             */
            if ($receipt && $receipt->status === 'verified') {
                $existingSuccess = DB::table('panel_transactions')
                    ->where('panel_user_id', $panelUserId)
                    ->where('type', 'wallet_topup_card')
                    ->where('status', 'success')
                    ->where(function ($query) use ($receipt) {
                        $query
                            ->where(function ($q) use ($receipt) {
                                $q->where(
                                    'reference_type',
                                    'panel_wallet_receipt'
                                )->where(
                                    'reference_id',
                                    $receipt->id
                                );
                            })
                            ->orWhere(function ($q) use ($receipt) {
                                $q->where(
                                    'reference_type',
                                    'panel_wallet_receipts'
                                )->where(
                                    'reference_id',
                                    $receipt->id
                                );
                            });
                    })
                    ->lockForUpdate()
                    ->first();

                if ($existingSuccess) {
                    $topup->status = 'paid';
                    $topup->approval_source = $source;
                    $topup->paid_at = $topup->paid_at ?: now();
                    $topup->transaction_id = (int) $existingSuccess->id;
                    $topup->sms_match_key = null;

                    if ($source === 'sms' && !$topup->sms_paid_at) {
                        $topup->sms_paid_at = now();
                    }

                    if ($receipt->commission_paid) {
                        $topup->commission_paid = true;
                        $topup->commission_tx_id =
                            $receipt->commission_tx_id;
                    }

                    $topup->save();

                    return [
                        'ok' => false,
                        'code' => 'ALREADY_PAID',
                        'topup_id' => (int) $topup->id,
                        'transaction_id' =>
                            (int) $existingSuccess->id,
                    ];
                }
            }

            /*
             * محافظ دوم برای Payment بدون Receipt.
             */
            $existingTopupTx = DB::table('panel_transactions')
                ->where('panel_user_id', $panelUserId)
                ->where('type', 'wallet_topup_card')
                ->where('status', 'success')
                ->where(function ($query) use ($topup) {
                    $query
                        ->where(
                            'idempotency_key',
                            'panel_wallet_card_topup:' . $topup->id
                        )
                        ->orWhere(function ($q) use ($topup) {
                            $q->where(
                                'reference_type',
                                'panel_wallet_card_topup'
                            )->where(
                                'reference_id',
                                $topup->id
                            );
                        });
                })
                ->lockForUpdate()
                ->first();

            if ($existingTopupTx) {
                $topup->status = 'paid';
                $topup->approval_source = $source;
                $topup->paid_at = $topup->paid_at ?: now();
                $topup->transaction_id =
                    (int) $existingTopupTx->id;
                $topup->sms_match_key = null;

                if ($source === 'sms' && !$topup->sms_paid_at) {
                    $topup->sms_paid_at = now();
                }

                $topup->save();

                return [
                    'ok' => false,
                    'code' => 'ALREADY_PAID',
                    'topup_id' => (int) $topup->id,
                    'transaction_id' =>
                        (int) $existingTopupTx->id,
                ];
            }

            /*
             * کیف پول را Lock می‌کنیم.
             */
            $walletUser = DB::table('panel_users')
                ->where('id', $panelUserId)
                ->lockForUpdate()
                ->first();

            if (!$walletUser) {
                throw new RuntimeException(
                    'کاربر کیف پول پیدا نشد.'
                );
            }

            $before = (int) ($walletUser->credit ?? 0);
            $after = $before + $amount;

            $actorRole = $actorId
                ? DB::table('panel_users')
                    ->where('id', $actorId)
                    ->value('role')
                : 'system';

            $transactionId = null;

            /*
             * اگر Receipt قبلاً Submit شده باشد،
             * همان Transaction pending را Success می‌کنیم.
             */
            $pendingTx = null;

            if ($receipt) {
                $pendingTx = DB::table('panel_transactions')
                    ->where('panel_user_id', $panelUserId)
                    ->where('type', 'wallet_topup_card')
                    ->where('status', 'pending')
                    ->where(
                        'reference_type',
                        'panel_wallet_receipt'
                    )
                    ->where(
                        'reference_id',
                        $receipt->id
                    )
                    ->lockForUpdate()
                    ->first();
            }

            if ($pendingTx) {
                $txMeta = [];

                if (!empty($pendingTx->meta)) {
                    try {
                        $decoded = json_decode(
                            (string) $pendingTx->meta,
                            true
                        );

                        if (is_array($decoded)) {
                            $txMeta = $decoded;
                        }
                    } catch (\Throwable $e) {
                        $txMeta = [];
                    }
                }

                $txMeta['method'] = 'card';
                $txMeta['approval_source'] = $source;
                $txMeta['card_topup_id'] =
                    (int) $topup->id;
                $txMeta['payable_amount_irt'] =
                    (int) $topup->payable_amount_irt;

                DB::table('panel_transactions')
                    ->where('id', $pendingTx->id)
                    ->update([
                        'amount' => $amount,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'status' => 'success',
                        'performed_by_id' => $actorId,
                        'performed_by_role' => $actorRole,
                        'meta' => json_encode(
                            $txMeta,
                            JSON_UNESCAPED_UNICODE
                        ),
                        'updated_at' => now(),
                    ]);

                $transactionId =
                    (int) $pendingTx->id;

            } else {
                /*
                 * SMS مستقیم و بدون Receipt.
                 */
                $idempotencyKey =
                    'panel_wallet_card_topup:' . $topup->id;

                $transactionId =
                    (int) DB::table('panel_transactions')
                        ->insertGetId([
                            'panel_user_id' =>
                                $panelUserId,

                            'type' =>
                                'wallet_topup_card',

                            'direction' =>
                                'credit',

                            'amount' =>
                                $amount,

                            'balance_before' =>
                                $before,

                            'balance_after' =>
                                $after,

                            'reference_type' =>
                                'panel_wallet_card_topup',

                            'reference_id' =>
                                (int) $topup->id,

                            'quantity' =>
                                1,

                            'currency' =>
                                'IRT',

                            'status' =>
                                'success',

                            'performed_by_id' =>
                                $actorId,

                            'performed_by_role' =>
                                $actorRole,

                            'idempotency_key' =>
                                $idempotencyKey,

                            'meta' => json_encode([
                                'method' => 'card',
                                'approval_source' =>
                                    $source,
                                'created_via' =>
                                    (string)
                                    $topup->created_via,
                                'card_topup_id' =>
                                    (int) $topup->id,
                                'payable_amount_irt' =>
                                    (int)
                                    $topup
                                        ->payable_amount_irt,
                                'unique_extra' =>
                                    (int)
                                    $topup->unique_extra,
                            ], JSON_UNESCAPED_UNICODE),

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);
            }

            /*
             * افزایش موجودی فقط یک بار.
             */
            DB::table('panel_users')
                ->where('id', $panelUserId)
                ->update([
                    'credit' => $after,
                    'updated_at' => now(),
                ]);

            /*
             * کمیسیون معرف.
             */
            $commissionTxId = null;
            $commissionPaid = false;

            if ($receipt && $receipt->commission_paid) {
                $commissionPaid = true;
                $commissionTxId =
                    $receipt->commission_tx_id
                        ? (int)
                            $receipt->commission_tx_id
                        : null;

            } elseif ($topup->commission_paid) {
                $commissionPaid = true;
                $commissionTxId =
                    $topup->commission_tx_id
                        ? (int)
                            $topup->commission_tx_id
                        : null;

            } else {
                $payer = DB::table('panel_users')
                    ->select(
                        'id',
                        'email',
                        'name',
                        'code',
                        'referred_by_id',
                        'ref_commission_rate'
                    )
                    ->where(
                        'id',
                        $panelUserId
                    )
                    ->first();

                if ($payer) {
                    $referrerId =
                        (int)
                        ($payer->referred_by_id ?? 0);

                    $rate =
                        (float)
                        ($payer->ref_commission_rate ?? 0);

                    if (
                        $referrerId > 0
                        &&
                        $referrerId
                            !==
                            (int) $payer->id
                        &&
                        $rate > 0
                    ) {
                        $commission =
                            (int) floor(
                                $amount
                                *
                                $rate
                                /
                                100
                            );

                        if ($commission > 0) {
                            $commissionTxId =
                                app(
                                    TransactionLogger::class
                                )
                                ->walletReferrerCommissionAwarded(
                                    $referrerId,
                                    $commission,
                                    [
                                        'source_user_id' =>
                                            (int)
                                            $payer->id,

                                        'source_user_email' =>
                                            (string)
                                            ($payer->email ?? ''),

                                        'source_user_code' =>
                                            (string)
                                            ($payer->code ?? ''),

                                        'source_user_name' =>
                                            (string)
                                            ($payer->name ?? ''),

                                        'source_amount' =>
                                            $amount,

                                        'commission_rate' =>
                                            $rate,

                                        'source_receipt_id' =>
                                            $receipt
                                                ? (int)
                                                    $receipt->id
                                                : null,

                                        'source_card_topup_id' =>
                                            (int)
                                            $topup->id,

                                        'user_ids' => [
                                            (int)
                                            $payer->id
                                        ],
                                    ],
                                    $actorId
                                );

                            $commissionPaid =
                                $commissionTxId > 0;
                        }
                    }
                }
            }

            /*
             * اگر Receipt وجود دارد همان Receipt فعلی
             * verified می‌شود.
             *
             * مبلغ Receipt = مبلغ اصلی شارژ،
             * نه مبلغ دقیق بانکی.
             */
            if ($receipt) {
                $receipt->amount = $amount;
                $receipt->status = 'verified';
                $receipt->notified_at = null;

                if ($commissionPaid) {
                    $receipt->commission_paid = true;
                    $receipt->commission_tx_id =
                        $commissionTxId;
                }

                $receipt->save();
            }

            /*
             * وضعیت نهایی Payment.
             */
            $topup->status = 'paid';
            $topup->approval_source = $source;
            $topup->paid_at = now();
            $topup->transaction_id =
                $transactionId;
            $topup->sms_match_key = null;

            /*
            * اگر Receipt وجود دارد، Toast اصلی از مسیر همان Receipt می‌آید.
            * پس خود CardTopup نباید Toast دوم تولید کند.
            */
            $topup->notified_at =
                $receipt ? now() : null;

            /*
            * اگر کمیسیون متعلق به Payment دارای Receipt است،
            * Toast کمیسیون نیز از مسیر قدیمی Receipt نمایش داده می‌شود.
            * Direct SMS بدون Receipt همچنان null می‌ماند.
            */
            $topup->commission_notified_at =
                ($receipt && $commissionPaid)
                    ? now()
                    : null;

            if ($source === 'sms') {
                $topup->sms_paid_at = now();
            }

            if ($commissionPaid) {
                $topup->commission_paid = true;
                $topup->commission_tx_id =
                    $commissionTxId;
            }

            $topup->save();

            /*
            * پاک‌سازی کامل پیام‌های پرداخت ربات نمایندگان و
            * ارسال پیام موفقیت فقط بعد از Commit.
            * این مسیر برای SMS / Manual / AI مشترک است.
            */
            $topupIdForTelegram = (int)$topup->id;

            DB::afterCommit(
                function () use (
                    $topupIdForTelegram,
                    $amount,
                    $after
                ) {
                    try {
                        app(
                            TelegramNotifier::class
                        )->finalizePanelWalletCardTopupUser(
                            $topupIdForTelegram,
                            $amount,
                            $after
                        );
                    } catch (\Throwable $e) {
                        // خطای تلگرام نباید
                        // تراکنش مالی را خراب کند.
                    }
                }
            );

            return [
                'ok' => true,
                'code' => 'OK',

                'topup_id' =>
                    (int) $topup->id,

                'receipt_id' =>
                    $receipt
                        ? (int) $receipt->id
                        : null,

                'transaction_id' =>
                    $transactionId,

                'amount' =>
                    $amount,

                'balance_before' =>
                    $before,

                'balance_after' =>
                    $after,

                'commission_paid' =>
                    $commissionPaid,

                'commission_tx_id' =>
                    $commissionTxId,

                'approval_source' =>
                    $source,
            ];
        }, 3);
    }
}