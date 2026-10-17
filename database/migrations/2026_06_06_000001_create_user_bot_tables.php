<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20); // admin | partner
            $table->unsignedBigInteger('owner_id');

            $table->text('bot_token')->nullable();
            $table->string('webhook_secret', 100)->nullable();
            $table->string('owner_telegram_chat_id', 80)->nullable();
            $table->string('owner_connect_code', 64)->nullable();

            $table->boolean('active_users_only')->default(true);
            $table->boolean('is_enabled')->default(false);

            $table->string('card_number', 32)->nullable();
            $table->string('card_holder', 120)->nullable();
            $table->text('card_description')->nullable();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
        });

        Schema::create('user_bot_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');

            $table->string('plan_key', 80);
            $table->string('title', 160);
            $table->unsignedBigInteger('price')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true);

            $table->timestamps();

            $table->unique(['setting_id', 'plan_key']);
            $table->foreign('setting_id')->references('id')->on('user_bot_settings')->cascadeOnDelete();
        });

        Schema::create('user_bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');

            $table->string('telegram_chat_id', 80);
            $table->string('telegram_user_id', 80)->nullable();
            $table->string('telegram_username', 120)->nullable();

            $table->boolean('is_logged_in')->default(false);
            $table->string('account_email')->nullable();
            $table->string('account_sub_link', 1000)->nullable();
            $table->json('account_snapshot')->nullable();

            $table->string('state', 80)->nullable();
            $table->json('state_data')->nullable();

            $table->timestamps();

            $table->unique(['setting_id', 'telegram_chat_id']);
            $table->foreign('setting_id')->references('id')->on('user_bot_settings')->cascadeOnDelete();
        });

        Schema::create('user_bot_orders', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('session_id')->nullable();

            $table->string('order_type', 40); // buy_account | renew_account | buy_traffic
            $table->string('plan_key', 80)->nullable();
            $table->string('account_email')->nullable();
            $table->unsignedInteger('traffic_gb')->nullable();
            $table->unsignedBigInteger('amount')->default(0);

            $table->string('status', 30)->default('pending'); // pending | rejected | done | failed
            $table->string('receipt_file_id')->nullable();
            $table->string('receipt_file_unique_id')->nullable();

            $table->json('payload')->nullable();
            $table->text('admin_note')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['setting_id', 'status']);
            $table->foreign('setting_id')->references('id')->on('user_bot_settings')->cascadeOnDelete();
            $table->foreign('session_id')->references('id')->on('user_bot_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_bot_orders');
        Schema::dropIfExists('user_bot_sessions');
        Schema::dropIfExists('user_bot_plans');
        Schema::dropIfExists('user_bot_settings');
    }
};