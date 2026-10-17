<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bot_sms_devices', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('setting_id');

            $table->string('name', 120);

            /*
             * ios | android
             */
            $table->string('platform', 20);

            /*
             * توکن خام ذخیره نمی‌شود.
             * فقط SHA-256 آن ذخیره خواهد شد.
             */
            $table->string('token_hash', 64)
                ->unique();

            $table->boolean('is_enabled')
                ->default(true);

            $table->timestamp('last_seen_at')
                ->nullable();

            $table->string('last_ip', 45)
                ->nullable();

            $table->json('metadata')
                ->nullable();

            $table->timestamps();

            $table->index([
                'setting_id',
                'is_enabled',
            ]);

            $table->foreign('setting_id')
                ->references('id')
                ->on('user_bot_settings')
                ->cascadeOnDelete();
        });

        Schema::create('user_bot_sms_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('setting_id');

            /*
             * برای جلوگیری از پردازش دوباره یک درخواست.
             */
            $table->string('event_id', 80);

            $table->string('platform', 20);

            /*
             * نسخه فیلتر محلی Shortcut یا اپ.
             */
            $table->string('local_filter_version', 40)
                ->nullable();

            /*
             * فقط تعداد مبلغ‌های احتمالی ثبت می‌شود.
             * خود لیست در دیتابیس ذخیره نمی‌شود.
             */
            $table->unsignedTinyInteger('candidate_count')
                ->default(0);

            $table->string('status', 40);

            $table->unsignedBigInteger('matched_order_id')
                ->nullable();

            /*
             * مبلغ نهایی سفارش به تومان.
             */
            $table->unsignedBigInteger('matched_amount_toman')
                ->nullable();

            /*
             * عددی که واقعاً از دستگاه آمده است.
             * ممکن است تومان یا ریال باشد.
             */
            $table->unsignedBigInteger('matched_numeric_value')
                ->nullable();

            $table->text('failure_reason')
                ->nullable();

            $table->dateTime('received_at_device')
                ->nullable();

            $table->dateTime('received_at_server');

            $table->string('request_ip', 45)
                ->nullable();

            $table->timestamps();

            $table->unique(
                [
                    'device_id',
                    'event_id',
                ],
                'user_bot_sms_events_device_event_unique'
            );

            $table->index(
                [
                    'setting_id',
                    'status',
                    'created_at',
                ],
                'user_bot_sms_events_setting_status_idx'
            );

            $table->foreign('device_id')
                ->references('id')
                ->on('user_bot_sms_devices')
                ->cascadeOnDelete();

            $table->foreign('setting_id')
                ->references('id')
                ->on('user_bot_settings')
                ->cascadeOnDelete();

            $table->foreign('matched_order_id')
                ->references('id')
                ->on('user_bot_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_bot_sms_events');
        Schema::dropIfExists('user_bot_sms_devices');
    }
};