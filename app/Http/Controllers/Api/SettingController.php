<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RuntimeSetting;
use App\Utils\Helper;

class SettingController extends Controller
{
    /**
     * Legacy endpoint: only plain URLs can safely be rebuilt from two parts.
     * Account clients should consume their API's complete subscribe_url.
     */
    public function linkParts()
    {
        $part2 = trim((string) RuntimeSetting::get('subscribe_path', 's'), '/') ?: 's';
        $url = Helper::getSubscribeUrl('');
        $parts = parse_url($url);
        $suffix = '/'.$part2.'/';

        // A query, fragment or rewritten path cannot survive legacy concatenation.
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['query']) || isset($parts['fragment'])
            || !str_ends_with($url, $suffix)) {
            return response()->json([
                'status' => 'error',
                'code' => 'subscription_url_not_splittable',
                'message' => 'این لینک قابل ساخت از قطعه‌ها نیست؛ از لینک کامل subscribe_url حساب استفاده کنید.',
            ], 422);
        }

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'part1' => substr($url, 0, -strlen($suffix)),
                'part2' => $part2,
            ],
        ], 200);
    }
}

