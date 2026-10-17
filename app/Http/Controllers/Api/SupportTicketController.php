<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Services\SupportTicketTelegramNotifier;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $status = $request->get('status', 'all');

        $q = SupportTicket::query()
            ->where('user_id', $user->id)
            ->withCount([
                'messages as unread_count' => function ($q) {
                    $q->where('sender_role', 'admin')->where('is_read_by_user', false);
                }
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if (in_array($status, ['open', 'answered', 'closed'], true)) {
            $q->where('status', $status);
        }

        return response()->json($q->paginate(15));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', 'in:normal,important'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $data['subject'] = trim((string) $data['subject']);
        $data['message'] = isset($data['message']) ? trim((string) $data['message']) : null;

        if ($data['subject'] === '') {
            return response()->json([
                'message' => 'موضوع تیکت الزامی است.'
            ], 422);
        }

        if (blank($data['message'] ?? null) && !$request->hasFile('attachments')) {
            return response()->json([
                'message' => 'متن پیام یا فایل ضمیمه الزامی است.'
            ], 422);
        }

        $user = $request->user();

        $ticket = DB::transaction(function () use ($request, $data, $user) {
            do {
                $ticketNumber = random_int(10000, 99999);
            } while (
                SupportTicket::where('ticket_number', $ticketNumber)->exists()
            );

            $ticket = SupportTicket::create([
                'ticket_number' => $ticketNumber,
                'user_id' => $user->id,
                'subject' => $data['subject'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => 'open',
                'last_message_at' => now(),
            ]);

            $message = SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'sender_id' => $user->id,
                'sender_role' => 'user',
                'message' => $data['message'] ?? null,
                'is_read_by_user' => true,
                'is_read_by_admin' => false,
            ]);

            $this->storeAttachments($request, $ticket->id, $message->id);

            return $ticket->load(['messages.attachments']);
        });

        app(SupportTicketTelegramNotifier::class)->notifyAdminsNewTicket($ticket);

        return response()->json(['ok' => true, 'data' => $ticket], 201);
    }

    public function show(Request $request, int $id)
    {
        $user = $request->user();

        $ticket = SupportTicket::where('user_id', $user->id)
            ->with(['messages.attachments', 'messages.sender:id,name,email,role'])
            ->findOrFail($id);

        SupportTicketMessage::where('ticket_id', $ticket->id)
            ->where('sender_role', 'admin')
            ->where('is_read_by_user', false)
            ->update(['is_read_by_user' => true]);

        return response()->json($ticket->fresh(['messages.attachments', 'messages.sender:id,name,email,role']));
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

        $user = $request->user();

        $ticket = SupportTicket::where('user_id', $user->id)->findOrFail($id);

        if ($ticket->status === 'closed') {
            return response()->json([
                'message' => 'این تیکت بسته شده است.'
            ], 422);
        }

        DB::transaction(function () use ($request, $data, $user, $ticket) {
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'sender_id' => $user->id,
                'sender_role' => 'user',
                'message' => $data['message'] ?? null,
                'is_read_by_user' => true,
                'is_read_by_admin' => false,
            ]);

            $this->storeAttachments($request, $ticket->id, $message->id);

            $ticket->update([
                'status' => 'open',
                'last_message_at' => now(),
            ]);
        });

        app(SupportTicketTelegramNotifier::class)->notifyAdminsUserReply($ticket);

        return response()->json(['ok' => true]);
    }

    public function close(Request $request, int $id)
    {
        $ticket = SupportTicket::where('user_id', $request->user()->id)->findOrFail($id);

        $ticket->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(app(\App\Services\PartnerNotificationState::class)
            ->snapshot((int) $request->user()->id));
    }

    public function attachment(Request $request, int $id)
    {
        $attachment = SupportTicketAttachment::with('message.ticket')->findOrFail($id);

        if ((int) $attachment->message->ticket->user_id !== (int) $request->user()->id) {
            abort(403);
        }

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
