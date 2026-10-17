<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubscriptionTemplates
{
    public const FORMATS = ['singbox' => 'Sing-box', 'clash' => 'Clash', 'clashmeta' => 'Clash Meta', 'stash' => 'Stash', 'surge' => 'Surge', 'surfboard' => 'Surfboard'];

    public static function get(string $name): string
    {
        if (Schema::hasTable('v2_subscribe_templates')) {
            $content = DB::table('v2_subscribe_templates')->where('name', $name)->value('content');
            if (is_string($content) && trim($content) !== '') {
                return $content;
            }
        }
        return self::defaultContent($name);
    }

    public static function defaultContent(string $name): string
    {
        $file = match ($name) {
            'clash', 'clashmeta', 'stash' => 'default.clash.yaml',
            'singbox' => 'default.sing-box.json',
            'surge' => 'default.surge.conf',
            'surfboard' => 'default.surfboard.conf',
            default => throw new \InvalidArgumentException('Unknown subscription format.'),
        };
        return file_get_contents(resource_path('rules/'.$file));
    }

    public static function save(string $name, string $content): void
    {
        self::assertFormat($name);
        try {
            if ($name === 'singbox') {
                $parsed = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parsed)) throw new \InvalidArgumentException('JSON object required.');
            } elseif (in_array($name, ['clash', 'clashmeta', 'stash'], true)) {
                $parsed = \Symfony\Component\Yaml\Yaml::parse($content);
                if (!is_array($parsed)) throw new \InvalidArgumentException('YAML mapping required.');
            }
        } catch (\Throwable $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['content' => 'ساختار قالب معتبر نیست: '.$e->getMessage()]);
        }
        $data = ['content' => $content, 'updated_at' => now()];
        if (DB::table('v2_subscribe_templates')->where('name', $name)->exists()) {
            DB::table('v2_subscribe_templates')->where('name', $name)->update($data);
        } else {
            DB::table('v2_subscribe_templates')->insert(['name' => $name, 'created_at' => now()] + $data);
        }
        self::flushCache($name);
    }

    public static function reset(string $name): void
    {
        self::assertFormat($name);
        DB::table('v2_subscribe_templates')->where('name', $name)->delete();
        self::flushCache($name);
    }

    private static function assertFormat(string $name): void
    {
        abort_unless(isset(self::FORMATS[$name]), 404);
    }

    private static function flushCache(string $name): void
    {
        \Illuminate\Support\Facades\Cache::forget('subscribe_template:'.$name);
    }
}

