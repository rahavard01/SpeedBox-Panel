<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultGroupId = DB::table('panel_tariff_groups')
            ->where('is_default', 1)
            ->value('id');

        if (!$defaultGroupId) {
            $defaultGroupId = DB::table('panel_tariff_groups')
                ->orderBy('id')
                ->value('id');
        }

        if (!$defaultGroupId) {
            return;
        }

        DB::table('panel_plan')->updateOrInsert(
            [
                'tariff_group_id' => $defaultGroupId,
                'plan_key' => 'volume_gig',
            ],
            [
                'name' => 'پلن حجمی',
                'default_price' => '',
                'details' => '',
                'enable' => 1,
                'v2_plan_id' => null,
            ]
        );
    }

    public function down(): void
    {
        DB::table('panel_plan')->where('plan_key', 'volume_gig')->delete();
    }
};