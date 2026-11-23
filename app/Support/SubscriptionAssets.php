<?php

namespace App\Support;

final class SubscriptionAssets
{
    public static function fontUrl(): ?string
    {
        $path = public_path('build/manifest.json');
        if (!is_file($path)) return null;

        $manifest = json_decode((string) file_get_contents($path), true);
        if (!is_array($manifest)) return null;

        // Vite may prefix the package path when node_modules is shared across checkouts.
        $source = '/node_modules/@fontsource-variable/vazirmatn/files/vazirmatn-arabic-wght-normal.woff2';
        foreach ($manifest as $key => $entry) {
            if (!str_ends_with('/' . $key, $source)) continue;

            $file = $entry['file'] ?? null;
            if (is_string($file)
                && preg_match('~^assets/[A-Za-z0-9._-]+\.woff2$~D', $file)
                && is_file(public_path('build/' . $file))) {
                return '/build/' . $file;
            }
        }

        return null;
    }
}
