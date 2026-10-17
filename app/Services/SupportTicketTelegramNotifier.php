<?php

namespace App\Services;

use App\Models\SupportTicket;
use Illuminate\Support\Facades\Log;

class SupportTicketTelegramNotifier
{
    public function notifyAdminsNewTicket(SupportTicket $ticket): void
    {
        try {
            $ticket->loadMissing('user');

            $user = $ticket->user;

            $text =
                "🎫 تیکت جدید ثبت شد\n\n" .
                "شماره تیکت: {$ticket->ticket_number}\n" .
                "موضوع: {$ticket->subject}\n" .
                "اولویت: " . $this->priorityLabel($ticket->priority) . "\n" .
                "کاربر: " . ($user?->code ?: '—') . "\n\n" .
                "جهت مشاهده، به پنل مراجعه کنید.";

            app(TelegramNotifier::class)->sendTextToAdmins($text);
        } catch (\Throwable $e) {
            Log::warning('Support ticket admin telegram notification failed', [
                'ticket_id' => $ticket->ticket_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyAdminsUserReply(SupportTicket $ticket): void
    {
        try {
            $ticket->loadMissing('user');

            $user = $ticket->user;

            $text =
                "💬 پاسخ جدید برای تیکت شماره {$ticket->ticket_number} ثبت شد\n\n" .
                "موضوع: {$ticket->subject}\n" .
                "اولویت: " . $this->priorityLabel($ticket->priority) . "\n" .
                "کاربر: " . ($user?->code ?: '—') . "\n\n" .
                "جهت مشاهده، به پنل مراجعه کنید.";

            app(TelegramNotifier::class)->sendTextToAdmins($text);
        } catch (\Throwable $e) {
            Log::warning('Support ticket user reply telegram notification failed', [
                'ticket_id' => $ticket->ticket_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyUserAdminReply(SupportTicket $ticket): void
    {
        try {
            $ticket->loadMissing('user');

            $user = $ticket->user;

            if (!$user || empty($user->telegram_user_id)) {
                return;
            }

            $text =
                "🎫 پاسخ جدیدی برای تیکت شماره {$ticket->ticket_number} ثبت شد\n\n" .
                "موضوع: {$ticket->subject}\n\n" .
                "جهت مشاهده پاسخ، به پنل مراجعه کنید.";

            app(TelegramNotifier::class)->sendTextTo((string) $user->telegram_user_id, $text);
        } catch (\Throwable $e) {
            Log::warning('Support ticket user telegram notification failed', [
                'ticket_id' => $ticket->ticket_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function priorityLabel(?string $priority): string
    {
        return $priority === 'important' ? 'فوری' : 'معمولی';
    }
}