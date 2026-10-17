<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class PartnerCodeLock
{
    public function locked(object $owner): bool
    {
        return (bool) ($owner->partner_code_locked ?? false)
            || ((int) $owner->role !== 1 && PanelAccountOwnership::scope(DB::table('v2_user'), (string) $owner->code)->exists());
    }

    public function remember(object $owner): void
    {
        if ($this->locked($owner) && empty($owner->partner_code_locked)) {
            DB::table('panel_users')->where('id', $owner->id)->update(['partner_code_locked' => true]);
        }
    }
}
