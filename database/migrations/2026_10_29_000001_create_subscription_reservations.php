<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // MySQL DDL survives a failed migration. Resume without replacing paid rows.
        if (!Schema::hasColumn('v2_user', 'subscription_generation')) {
            Schema::table('v2_user', fn (Blueprint $table) => $table->unsignedBigInteger('subscription_generation')->default(0));
        }
        if (!Schema::hasColumn('v2_user', 'account_reset_traffic_method')) {
            Schema::table('v2_user', fn (Blueprint $table) => $table->unsignedTinyInteger('account_reset_traffic_method')->nullable());
        }
        if (!Schema::hasTable('speedbox_subscription_reservations')) {
            Schema::create('speedbox_subscription_reservations', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->index();
                // NULL for history; the unique index enforces one waiting reservation per account.
                $table->unsignedBigInteger('reserved_user_id')->nullable()->unique();
                $table->unsignedBigInteger('transaction_id')->unique();
                $table->unsignedBigInteger('bot_order_id')->nullable()->unique();
                $table->unsignedBigInteger('panel_user_id');
                $table->unsignedBigInteger('plan_id')->index();
                $table->unsignedBigInteger('tariff_group_id')->index();
                $table->string('state', 24)->index();
                $table->boolean('trigger_on_quota');
                $table->json('snapshot');
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->timestamp('notify_after')->nullable()->index();
                $table->unsignedInteger('notify_attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('speedbox_reservation_notifications')) {
            Schema::create('speedbox_reservation_notifications', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('reservation_id');
                $table->unsignedBigInteger('session_id');
                $table->unique(['reservation_id', 'session_id'], 'reservation_session_notification');
                $table->text('message');
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('retry_at')->useCurrent()->index();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
            });
        }
        if (!Schema::hasTable('speedbox_traffic_batches')) {
            Schema::create('speedbox_traffic_batches', function (Blueprint $table) {
                $table->unsignedBigInteger('node_id');
                $table->string('batch_id', 32);
                $table->timestamp('created_at')->useCurrent()->index();
                $table->primary(['node_id', 'batch_id']);
            });
        }
        if (!Schema::hasTable('speedbox_account_purchase_requests')) {
            Schema::create('speedbox_account_purchase_requests', function (Blueprint $table) {
                $table->string('request_key', 64)->primary();
                $table->string('fingerprint', 64);
                $table->json('result');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Paid reservations and their audit trail must survive an application rollback.
        throw new RuntimeException('Resolve paid subscription reservations before removing this schema.');
    }
};
