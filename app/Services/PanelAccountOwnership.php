<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

final class PanelAccountOwnership
{
    public static function matches(string $email, string $code): bool
    {
        $email = strtolower(trim($email));
        $code = strtolower(trim($code));
        if ($code === '' || !str_contains($email, '@')) return false;
        [$local, $domain] = explode('@', $email, 2);
        return $local === $code || explode('.', $domain)[0] === $code;
    }

    public static function scope(Builder $query, string $code, string $column = 'email'): Builder
    {
        // Column is a server-owned identifier, never request input.
        $code = strtolower(trim($code));
        if ($code === '') return $query->whereRaw('1 = 0');
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $code);
        return $query->where(function (Builder $where) use ($column, $escaped, $code) {
            $where->whereRaw("LOWER(TRIM({$column})) LIKE ? ESCAPE '!'", [$escaped . '@%']);
            if (str_contains($code, '.')) return;
            $where->orWhereRaw("LOWER(TRIM({$column})) LIKE ? ESCAPE '!'", ['%@' . $escaped . '.%'])
                ->orWhereRaw("LOWER(TRIM({$column})) LIKE ? ESCAPE '!'", ['%@' . $escaped]);
        });
    }
}
