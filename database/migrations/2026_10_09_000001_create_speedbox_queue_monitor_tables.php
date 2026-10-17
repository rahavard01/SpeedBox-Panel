<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speedbox_queue_activity', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->string('job_id', 190);
            $table->string('connection', 64);
            $table->string('queue', 100)->index();
            $table->string('name');
            $table->string('status', 24)->index();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->double('duration')->nullable();
            $table->string('exception_class')->nullable();
        });
        Schema::create('speedbox_queue_workers', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->string('connection', 64);
            $table->string('queues');
            $table->timestamp('last_seen_at')->index();
            $table->char('busy_job', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speedbox_queue_workers');
        Schema::dropIfExists('speedbox_queue_activity');
    }
};
