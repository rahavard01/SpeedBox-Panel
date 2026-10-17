<?php

namespace App\Services;

use App\Models\PanelAnnouncement;
use Illuminate\Support\Facades\DB;

final class PartnerNotificationState
{
    // Return both badge counts together; displaying a toast never marks a message read.
    public function snapshot(int $userId): array
    {
        $messages = DB::table('support_ticket_messages as m')
            ->join('support_tickets as t', 't.id', '=', 'm.ticket_id')
            ->where('t.user_id', $userId)
            ->where('m.sender_role', 'admin')
            ->where('m.is_read_by_user', false)
            ->orderBy('m.id')
            ->get(['m.id', 't.ticket_number'])
            ->map(fn ($message) => [
                'id' => (int) $message->id,
                'ticket_number' => (int) $message->ticket_number,
            ]);

        $announcements = PanelAnnouncement::publishedNow()
            ->whereDoesntHave('reads', fn ($query) =>
                $query->where('user_id', $userId)->whereNotNull('read_at')
            )->count();

        return [
            'count' => $messages->count(),
            'announcement_count' => (int) $announcements,
            'notification_ids' => $messages->pluck('id')->all(),
            'notifications' => $messages->all(),
        ];
    }
}
