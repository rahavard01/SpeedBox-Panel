<?php

namespace App\Support;

final class SubscriptionAssets
{
    public static function fontUrl(): ?string
    {
        $path = public_path('build/manifest.json');
        if (!is_file($path)) return null;
        $manifest = json_decode((string) file_get_contents($path), true);
        $file = $manifest['node_modules/@fontsource-variable/vazirmatn/files/vazirmatn-arabic-wght-normal.woff2']['file'] ?? null;
        if (!is_string($file) || !preg_match('~^assets/[A-Za-z0-9._-]+\.woff2$~D', $file)
            || !is_file(public_path('build/'.$file))) return null;
        return '/build/'.$file;
    }
}
