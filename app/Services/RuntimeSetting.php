<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RuntimeSetting
{
    public static function get(string $name, mixed $default = null): mixed
    {
        $stored = Cache::remember("speedbox:setting:v2:{$name}", 60, function () use ($name) {
            if (!Schema::hasTable('v2_settings')) {
                return ['value' => null];
            }

            $value = DB::table('v2_settings')->where('name', $name)->value('value');
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) $value = $decoded;
            }
            return ['value' => $value];
        });
        // A missing setting must not cache a caller-specific fallback.
        return $stored['value'] ?? $default;
    }

    public static function set(string $name, mixed $value, string $group = 'server'): void
    {
        $data = ['value' => is_bool($value) ? (string) (int) $value : (is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE)), 'updated_at' => now()];
        if (DB::table('v2_settings')->where('name', $name)->exists()) {
            DB::table('v2_settings')->where('name', $name)->update($data);
        } else {
            $insert = ['name' => $name, 'created_at' => now()] + $data;
            if (Schema::hasColumn('v2_settings', 'group')) $insert['group'] = $group;
            DB::table('v2_settings')->insert($insert);
        }
        DB::afterCommit(function () use ($name) {
            Cache::forget("speedbox:setting:{$name}");
            Cache::forget("speedbox:setting:v2:{$name}");
            Cache::forget('admin_settings');
        });
    }
}

