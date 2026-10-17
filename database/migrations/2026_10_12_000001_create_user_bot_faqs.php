<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_bot_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 128);
            $table->text('answer');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('user_bot_faqs'); }
};
