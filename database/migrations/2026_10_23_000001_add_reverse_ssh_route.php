<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('speedbox_managed_servers',function(Blueprint $table){
            $table->unsignedSmallInteger('reverse_ssh_port')->nullable()->unique();
        });
    }
    public function down(): void
    {
        Schema::table('speedbox_managed_servers',function(Blueprint $table){$table->dropUnique(['reverse_ssh_port']);$table->dropColumn('reverse_ssh_port');});
    }
};
