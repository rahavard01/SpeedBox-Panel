<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('speedbox_emergency_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->text('config_uri');
            $table->string('protocol', 24);
            $table->string('host', 255);
            $table->unsignedInteger('port');
            $table->boolean('show')->default(true);
            $table->unsignedInteger('sort')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speedbox_emergency_configs');
    }
};
