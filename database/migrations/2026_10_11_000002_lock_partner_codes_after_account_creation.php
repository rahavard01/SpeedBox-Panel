<?php

use App\Services\PanelAccountOwnership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('panel_users', function (Blueprint $table) {
            $table->boolean('partner_code_locked')->default(false);
        });

        // Purchases keep the lock for legacy owners whose accounts were deleted.
        $purchasedIds = []; $purchasedCodes = [];
        if (Schema::hasTable('panel_transactions') && Schema::hasColumn('panel_transactions', 'type') && Schema::hasColumn('panel_transactions', 'status')) {
            foreach (DB::table('panel_transactions')->whereIn('type', ['account_purchase', 'buy_account', 'purchase'])->where('status', 'success')->cursor() as $purchase) {
                $purchasedIds[(int) $purchase->panel_user_id] = true;
                $meta = json_decode((string) ($purchase->meta ?? ''), true) ?: [];
                $code = strtolower(trim((string) ($meta['panel_code'] ?? '')));
                if ($code !== '') $purchasedCodes[$code] = true;
            }
        }
        DB::table('panel_users')->where('role', '<>', 1)->orderBy('id')->chunkById(200, function ($owners) use ($purchasedIds, $purchasedCodes) {
            foreach ($owners as $owner) {
                $code = (string) ($owner->code ?? '');
                $locked = isset($purchasedIds[(int) $owner->id]) || isset($purchasedCodes[strtolower(trim($code))]);
                if (!$locked && Schema::hasTable('v2_user')) $locked = PanelAccountOwnership::scope(DB::table('v2_user'), $code)->exists();
                if (!$locked && Schema::hasTable('speedbox_account_operations')) $locked = PanelAccountOwnership::scope(DB::table('speedbox_account_operations'), $code, 'account_email')->exists();
                if ($locked) DB::table('panel_users')->where('id', $owner->id)->update(['partner_code_locked' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('panel_users', fn (Blueprint $table) => $table->dropColumn('partner_code_locked'));
    }
};
