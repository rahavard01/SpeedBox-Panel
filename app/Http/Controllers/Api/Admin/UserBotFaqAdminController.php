<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UserBotFaqAdminController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && (int) $request->user()->role === 1, 403);
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        return response()->json(['data' => DB::table('user_bot_faqs')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function update(Request $request)
    {
        $this->authorizeAdmin($request);
        $rows = $request->input('items');
        if (is_array($rows)) {
            foreach ($rows as &$row) {
                if (!is_array($row)) continue;
                foreach (['question', 'answer'] as $field) if (is_string($row[$field] ?? null)) $row[$field] = trim($row[$field]);
            }
            unset($row);
            $request->merge(['items' => $rows]);
        }
        $data = $request->validate([
            'items' => ['present', 'array', 'max:50'],
            'items.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'items.*.question' => ['required', 'string', 'max:128'],
            'items.*.answer' => ['required', 'string', 'max:3500'],
            'items.*.enabled' => ['required', 'boolean'],
        ], [
            'items.*.question.required' => 'متن سؤال را وارد کنید.',
            'items.*.answer.required' => 'پاسخ سؤال را وارد کنید.',
            'items.*.question.max' => 'عنوان سؤال بیش از حد طولانی است.',
            'items.*.answer.max' => 'پاسخ سؤال بیش از حد طولانی است.',
        ]);
        DB::transaction(function () use ($data) {
            $existing = DB::table('user_bot_faqs')->lockForUpdate()->pluck('id')->all();
            $keep = [];
            foreach ($data['items'] as $order => $row) {
                $id = isset($row['id']) ? (int) $row['id'] : null;
                if ($id && !in_array($id, $existing, true)) {
                    throw ValidationException::withMessages(['items' => 'فهرست پرسش‌ها تغییر کرده است؛ صفحه را تازه‌سازی کنید.']);
                }
                $values = ['question' => $row['question'], 'answer' => $row['answer'], 'enabled' => (bool) $row['enabled'], 'sort_order' => $order, 'updated_at' => now()];
                if ($id) DB::table('user_bot_faqs')->where('id', $id)->update($values);
                else $id = DB::table('user_bot_faqs')->insertGetId($values + ['created_at' => now()]);
                $keep[] = $id;
            }
            $delete = DB::table('user_bot_faqs');
            if ($keep) $delete->whereNotIn('id', $keep);
            $delete->delete();
        });
        return $this->index($request);
    }
}
