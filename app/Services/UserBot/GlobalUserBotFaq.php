<?php

namespace App\Services\UserBot;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class GlobalUserBotFaq
{
    public function active(): Collection
    {
        if (!Schema::hasTable('user_bot_faqs')) return collect();
        return DB::table('user_bot_faqs')->where('enabled', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function answer(int $id): ?string
    {
        $item = $this->active()->firstWhere('id', $id);
        // Editable text is plain text. Escape it before Telegram's HTML parser;
        // keep newlines and never treat administrator prose as markup.
        return $item ? '<b>'.e($item->question)."</b>\n\n".e($item->answer) : null;
    }
}
