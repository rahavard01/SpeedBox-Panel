<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('speedbox_subscription_cycles')) {
            Schema::create('speedbox_subscription_cycles', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->primary();
                $table->unsignedInteger('panel_user_id')->index();
                $table->unsignedInteger('plan_id')->nullable();
                $table->unsignedBigInteger('paid_amount')->default(0);
                $table->unsignedInteger('started_at');
                $table->unsignedBigInteger('expired_at')->nullable();
                $table->unsignedBigInteger('last_transaction_id')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('v2_subscribe_templates')) {
            Schema::create('v2_subscribe_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->mediumText('content')->nullable();
                $table->timestamps();
            });
        }
        // Import legacy template values without deleting the original settings or replacing custom templates.
        foreach (array_keys(\App\Services\SubscriptionTemplates::FORMATS) as $name) {
            if (DB::table('v2_subscribe_templates')->where('name', $name)->exists()) continue;
            $content = DB::table('v2_settings')->where('name', 'subscribe_template_'.$name)->value('value');
            // Sing-box's raw JSON is template text, not a setting array.
            if (is_string($content)) {
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) $content = $decoded;
            }
            if (!is_string($content) || trim($content) === '') {
                $files = match ($name) {
                    'singbox' => ['custom.sing-box.json'],
                    'clash' => ['custom.clash.yaml'],
                    'clashmeta' => ['custom.clashmeta.yaml', 'custom.clash.yaml'],
                    'stash' => ['custom.stash.yaml', 'custom.clash.yaml'],
                    'surge' => ['custom.surge.conf'],
                    'surfboard' => ['custom.surfboard.conf'],
                };
                foreach ($files as $file) {
                    if (is_file(resource_path('rules/'.$file))) { $content = file_get_contents(resource_path('rules/'.$file)); break; }
                }
            }
            if (is_string($content) && trim($content) !== '') {
                DB::table('v2_subscribe_templates')->insert(['name' => $name, 'content' => $content, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (['next_reset_at', 'last_reset_at', 'reset_count'] as $column) {
            if (!Schema::hasColumn('v2_user', $column)) {
                Schema::table('v2_user', function (Blueprint $table) use ($column) {
                    if ($column === 'reset_count') $table->unsignedInteger($column)->default(0);
                    else $table->unsignedInteger($column)->nullable();
                    if ($column === 'next_reset_at') $table->index($column, 'idx_next_reset_at');
                });
            }
        }
    }

    public function down(): void
    {
        // Keep imported XBoard tables and account data on rollback.
    }
};
