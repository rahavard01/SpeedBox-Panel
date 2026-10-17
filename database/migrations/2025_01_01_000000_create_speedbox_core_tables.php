<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('v2_plan')) {
            Schema::create('v2_plan', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('group_id');
                $table->unsignedInteger('transfer_enable')->default(0)->comment('GB');
                $table->string('name');
                $table->unsignedInteger('speed_limit')->nullable();
                $table->unsignedInteger('device_limit')->nullable();
                $table->boolean('show')->default(false);
                $table->unsignedInteger('sort')->nullable();
                $table->boolean('renew')->default(true);
                $table->text('content')->nullable();
                $table->integer('month_price')->nullable();
                $table->integer('quarter_price')->nullable();
                $table->integer('half_year_price')->nullable();
                $table->integer('year_price')->nullable();
                $table->integer('two_year_price')->nullable();
                $table->integer('three_year_price')->nullable();
                $table->integer('onetime_price')->nullable();
                $table->integer('reset_price')->nullable();
                $table->unsignedTinyInteger('reset_traffic_method')->nullable();
                $table->unsignedInteger('capacity_limit')->nullable();
                $table->json('tags')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }

        if (!Schema::hasTable('v2_user')) {
            Schema::create('v2_user', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('invite_user_id')->nullable();
                $table->unsignedBigInteger('telegram_id')->nullable();
                $table->string('email', 64)->unique();
                $table->string('password', 255);
                $table->string('password_algo', 16)->nullable();
                $table->string('password_salt', 32)->nullable();
                $table->integer('balance')->default(0);
                $table->unsignedTinyInteger('discount')->nullable();
                $table->unsignedTinyInteger('commission_type')->default(0);
                $table->unsignedTinyInteger('commission_rate')->nullable();
                $table->integer('commission_balance')->default(0);
                $table->unsignedInteger('t')->default(0);
                // Keep both operands signed: purchases can make u + d negative.
                $table->bigInteger('u')->default(0);
                $table->bigInteger('d')->default(0);
                $table->unsignedBigInteger('transfer_enable')->default(0);
                $table->boolean('banned')->default(false);
                $table->boolean('is_admin')->default(false);
                $table->unsignedInteger('last_login_at')->nullable();
                $table->boolean('is_staff')->default(false);
                $table->string('last_login_ip', 64)->nullable();
                $table->uuid('uuid')->unique();
                $table->unsignedInteger('group_id')->nullable()->index();
                $table->unsignedInteger('plan_id')->nullable()->index();
                $table->unsignedInteger('speed_limit')->nullable();
                $table->unsignedInteger('device_limit')->nullable();
                $table->boolean('remind_expire')->default(true);
                $table->boolean('remind_traffic')->default(true);
                $table->string('token', 32)->unique();
                $table->unsignedBigInteger('expired_at')->nullable()->default(0);
                $table->text('remarks')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->index(['expired_at', 'banned']);
            });
        }

        if (!Schema::hasTable('v2_settings')) {
            Schema::create('v2_settings', function (Blueprint $table) {
                $table->id();
                $table->string('group')->nullable();
                $table->string('type')->nullable();
                $table->string('name')->unique();
                $table->longText('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Core tables are intentionally preserved to make migration rollback non-destructive.
    }
};

