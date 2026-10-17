<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class TrafficResetHistory
{
    // Call while holding the account row lock, in the same transaction as the reset.
    public function record(object $before, array $update, string $type, string $source, array $metadata = []): void
    {
        $oldUp = (int) $before->u;
        $oldDown = (int) $before->d;
        $up = (int) ($update['u'] ?? $oldUp);
        $down = (int) ($update['d'] ?? $oldDown);
        DB::table('v2_traffic_reset_logs')->insert([
            'user_id' => $before->id, 'reset_type' => $type,
            'reset_time' => now(), 'trigger_source' => $source,
            'old_upload' => $oldUp, 'old_download' => $oldDown, 'old_total' => $oldUp + $oldDown,
            'new_upload' => $up, 'new_download' => $down, 'new_total' => $up + $down,
            'metadata' => json_encode(['account_email' => $before->email] + $metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function renewal(object $before, array $update): void
    {
        if ((int) ($update['reset_count'] ?? 0) <= (int) ($before->reset_count ?? 0)) return;
        $operator = auth()->user();
        $this->record($before, $update, 'renewal', 'order', [
            'admin_id' => $operator?->id, 'operator_email' => $operator?->email,
            'reason' => 'بازنشانی هنگام تمدید',
        ]);
    }
}
