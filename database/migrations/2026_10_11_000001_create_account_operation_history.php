<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_traffic_reset_logs')) {
            Schema::create('v2_traffic_reset_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id');
                $table->string('reset_type', 32);
                $table->timestamp('reset_time');
                foreach (['old_upload', 'old_download', 'old_total', 'new_upload', 'new_download', 'new_total'] as $column) {
                    $table->bigInteger($column)->default(0);
                }
                $table->string('trigger_source', 32);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'reset_time']);
            });
        }
        Schema::create('speedbox_account_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('account_email', 64);
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('operator_email')->nullable();
            $table->string('action', 32);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speedbox_account_operations');
        // The reset table may already belong to an imported XBoard installation.
        // Keep its audit records on rollback rather than deleting shared history.
    }
};
