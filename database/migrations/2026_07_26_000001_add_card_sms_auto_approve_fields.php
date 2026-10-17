<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->boolean('card_sms_auto_approve_enabled')
                ->default(false)
                ->after('card_ai_auto_approve_enabled');
        });

        Schema::table('user_bot_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('card_sms_payable_amount')
                ->nullable()
                ->after('amount');

            $table->unsignedSmallInteger('card_sms_unique_extra')
                ->nullable()
                ->after('card_sms_payable_amount');

            $table->timestamp('card_sms_expires_at')
                ->nullable()
                ->after('card_sms_unique_extra');

            $table->string('card_sms_match_key', 120)
                ->nullable()
                ->after('card_sms_expires_at');

            $table->timestamp('card_sms_paid_at')
                ->nullable()
                ->after('card_sms_match_key');

            $table->timestamp('receipt_submitted_at')
                ->nullable()
                ->after('receipt_file_unique_id');

            $table->index(
                ['setting_id', 'status', 'card_sms_payable_amount'],
                'user_bot_orders_sms_match_idx'
            );

            $table->unique(
                'card_sms_match_key',
                'user_bot_orders_sms_match_key_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_orders', function (Blueprint $table) {
            $table->dropUnique('user_bot_orders_sms_match_key_unique');
            $table->dropIndex('user_bot_orders_sms_match_idx');

            $table->dropColumn([
                'card_sms_payable_amount',
                'card_sms_unique_extra',
                'card_sms_expires_at',
                'card_sms_match_key',
                'card_sms_paid_at',
                'receipt_submitted_at',
            ]);
        });

        Schema::table('user_bot_settings', function (Blueprint $table) {
            $table->dropColumn('card_sms_auto_approve_enabled');
        });
    }
};