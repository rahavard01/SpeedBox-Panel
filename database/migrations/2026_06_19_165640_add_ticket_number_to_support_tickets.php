<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->unsignedInteger('ticket_number')
                ->nullable()
                ->unique()
                ->after('id');
        });

        $tickets = DB::table('support_tickets')->get();

        foreach ($tickets as $ticket) {
            do {
                $number = random_int(10000, 99999);
            } while (
                DB::table('support_tickets')
                    ->where('ticket_number', $number)
                    ->exists()
            );

            DB::table('support_tickets')
                ->where('id', $ticket->id)
                ->update([
                    'ticket_number' => $number,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('ticket_number');
        });
    }
};