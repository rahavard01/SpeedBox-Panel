<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_payment_methods', function (Blueprint $table) {
            $table->boolean('sms_auto_approve_enabled')
                ->default(false)
                ->after('ai_auto_approve_enabled');
        });

        Schema::create('panel_wallet_card_topups', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');

            // مبلغ واقعی که باید به کیف پول اضافه شود
            $table->unsignedBigInteger('amount_irt');

            // مبلغ دقیق بانکی: مبلغ اصلی + شناسه یکتا
            $table->unsignedBigInteger('payable_amount_irt');

            // عدد یکتای اضافه‌شده؛ برای همکاران بعداً 1001 تا 1999
            $table->unsignedInteger('unique_extra');

            // کلید Match برای جلوگیری از برخورد و پردازش دوباره
            $table->string('sms_match_key', 120)
                ->nullable()
                ->unique();

            /*
             * pending
             * paid
             * expired
             * cancelled
             */
            $table->string('status', 24)
                ->default('pending');

            /*
             * panel
             * partner_bot
             */
            $table->string('created_via', 24)
                ->default('panel');

            // Snapshot اطلاعات کارت در زمان ساخت پرداخت
            $table->string('card_number', 32)
                ->nullable();

            $table->string('card_holder', 190)
                ->nullable();

            $table->timestamp('expires_at')
                ->nullable();

            $table->timestamp('sms_paid_at')
                ->nullable();

            $table->timestamp('paid_at')
                ->nullable();

            /*
             * sms
             * manual
             * ai
             */
            $table->string('approval_source', 24)
                ->nullable();

            $table->unsignedBigInteger('transaction_id')
                ->nullable();

            $table->boolean('commission_paid')
                ->default(false);

            $table->unsignedBigInteger('commission_tx_id')
                ->nullable();

            $table->timestamp('commission_notified_at')
                ->nullable();

            $table->timestamp('notified_at')
                ->nullable();

            $table->json('meta')
                ->nullable();

            $table->timestamps();

            $table->index(
                ['user_id', 'status'],
                'pwct_user_status_idx'
            );

            $table->index(
                ['status', 'payable_amount_irt'],
                'pwct_status_payable_idx'
            );

            $table->index(
                'expires_at',
                'pwct_expires_idx'
            );

            $table->foreign('user_id')
                ->references('id')
                ->on('panel_users')
                ->cascadeOnDelete();
        });

        /*
         * اگر رسید دستی ارسال شد، رسید به همان
         * Payment آنی متصل می‌شود.
         */
        Schema::table('panel_wallet_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('card_topup_id')
                ->nullable()
                ->after('user_id');

            $table->index(
                'card_topup_id',
                'pwr_card_topup_idx'
            );

            $table->foreign('card_topup_id')
                ->references('id')
                ->on('panel_wallet_card_topups')
                ->nullOnDelete();
        });

        /*
         * اگر SMS برای شارژ کیف پول همکار Match شد،
         * خود Event باید بداند مربوط به کدام Topup بوده.
         */
        Schema::table('user_bot_sms_events', function (Blueprint $table) {
            $table->unsignedBigInteger('matched_card_topup_id')
                ->nullable()
                ->after('matched_order_id');

            $table->foreign('matched_card_topup_id')
                ->references('id')
                ->on('panel_wallet_card_topups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_sms_events', function (Blueprint $table) {
            $table->dropForeign(['matched_card_topup_id']);
            $table->dropColumn('matched_card_topup_id');
        });

        Schema::table('panel_wallet_receipts', function (Blueprint $table) {
            $table->dropForeign(['card_topup_id']);
            $table->dropIndex('pwr_card_topup_idx');
            $table->dropColumn('card_topup_id');
        });

        Schema::dropIfExists('panel_wallet_card_topups');

        Schema::table('panel_payment_methods', function (Blueprint $table) {
            $table->dropColumn('sms_auto_approve_enabled');
        });
    }
};