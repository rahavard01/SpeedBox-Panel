<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\SupportTicketTelegramNotifier;

class SupportTicketAdminController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->get('status', 'all');
        $search = trim((string) $request->get('q', ''));

        $q = SupportTicket::query()
            ->with('user:id,name,email,code,role')
            ->withCount([
                'messages as unread_count' => function ($q) {
                    $q->where('sender_role', 'user')->where('is_read_by_admin', false);
                }
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if (in_array($status, ['open', 'answered', 'closed'], true)) {
            $q->where('status', $status);
        }

        if ($search !== '') {
            $q->where(function ($qq) use ($search) {
                $qq->where('subject', 'like', "%{$search}%")
                    ->orWhere('ticket_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                          ->orWhere('email', 'like', "%{$search}%")
                          ->orWhere('code', 'like', "%{$search}%");
                    });
            });
        }

        return response()->json($q->paginate(15));
    }

    public function show(Request $request, int $id)
    {
        $ticket = SupportTicket::with([
                'user:id,name,email,code,role',
                'messages.attachments',
                'messages.sender:id,name,email,role',
            ])
            ->findOrFail($id);

        SupportTicketMessage::where('ticket_id', $ticket->id)
            ->where('sender_role', 'user')
            ->where('is_read_by_admin', false)
            ->update(['is_read_by_admin' => true]);

        return response()->json($ticket->fresh([
            'user:id,name,email,code,role',
            'messages.attachments',
            'messages.sender:id,name,email,role',
        ]));
    }

    public function reply(Request $request, int $id)
    {
        $data = $request->validate([
            'message' => ['nullable', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        if (blank($data['message'] ?? null) && !$request->hasFile('attachments')) {
            return response()->json([
                'message' => 'متن پیام یا فایل ضمیمه الزامی است.'
            ], 422);
        }

        $admin = $request->user();
        $ticket = SupportTicket::findOrFail($id);

        if ($ticket->status === 'closed') {
            return response()->json([
                'message' => 'این تیکت بسته شده است.'
            ], 422);
        }

        DB::transaction(function () use ($request, $data, $admin, $ticket) {
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'sender_id' => $admin->id,
                'sender_role' => 'admin',
                'message' => $data['message'] ?? null,
                'is_read_by_user' => false,
                'is_read_by_admin' => true,
            ]);

            $this->storeAttachments($request, $ticket->id, $message->id);

            $ticket->update([
                'status' => 'answered',
                'last_message_at' => now(),
            ]);
        });

        app(SupportTicketTelegramNotifier::class)->notifyUserAdminReply($ticket);

        return response()->json(['ok' => true]);
    }

    public function close(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $ticket->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function reopen(Request $request, int $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $ticket->update([
            'status' => 'open',
            'closed_at' => null,
            'last_message_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function count(Request $request)
    {
        $ids = SupportTicketMessage::query()
            ->where('sender_role', 'user')
            ->where('is_read_by_admin', false)
            ->orderBy('id')->pluck('id');

        return response()->json(['count' => $ids->count(), 'notification_ids' => $ids->all()]);
    }

    public function attachment(Request $request, int $id)
    {
        $attachment = SupportTicketAttachment::findOrFail($id);

        if (!Storage::disk('local')->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    private function storeAttachments(Request $request, int $ticketId, int $messageId): void
    {
        if (!$request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments') as $file) {
            $path = $file->store("support-tickets/{$ticketId}", 'local');

            SupportTicketAttachment::create([
                'message_id' => $messageId,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize() ?: 0,
            ]);
        }
    }
}
