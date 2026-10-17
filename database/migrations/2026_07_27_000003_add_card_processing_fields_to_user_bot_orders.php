<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_bot_orders', function (Blueprint $table) {
            /*
             * توکن موقتی هر اجرای سفارش.
             * هر پردازش فقط با توکن خودش اجازه تغییر وضعیت دارد.
             */
            $table->string('card_processing_token', 64)
                ->nullable()
                ->after('card_sms_paid_at');

            /*
             * manual | ai | sms | sms_retry
             */
            $table->string('card_processing_source', 20)
                ->nullable()
                ->after('card_processing_token');

            $table->dateTime('card_processing_started_at')
                ->nullable()
                ->after('card_processing_source');

            $table->unsignedInteger('card_processing_attempts')
                ->default(0)
                ->after('card_processing_started_at');

            $table->text('card_processing_error')
                ->nullable()
                ->after('card_processing_attempts');

            $table->index(
                [
                    'status',
                    'card_processing_started_at',
                ],
                'user_bot_orders_card_processing_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_bot_orders', function (Blueprint $table) {
            $table->dropIndex(
                'user_bot_orders_card_processing_idx'
            );

            $table->dropColumn([
                'card_processing_token',
                'card_processing_source',
                'card_processing_started_at',
                'card_processing_attempts',
                'card_processing_error',
            ]);
        });
    }
};