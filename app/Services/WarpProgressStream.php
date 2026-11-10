<?php

namespace App\Services;

use App\Models\ServerDeployment;

/** Persist only fixed phase identifiers; arbitrary CLI output can contain licenses. */
final class WarpProgressStream
{
    private string $pending = '';
    private bool $discarding = false;
    private const PHASES = [
        'prerequisites' => [40, 'در حال بررسی پیش‌نیازهای WARP…'],
        'install' => [55, 'در حال دریافت و نصب بستهٔ WARP…'],
        'service' => [65, 'در حال آماده‌سازی سرویس WARP…'],
        'account' => [75, 'در حال بررسی و آماده‌سازی حساب WARP…'],
        'connect' => [85, 'در حال برقراری اتصال WARP…'],
        'verify' => [95, 'در حال تأیید خروج اینترنت از پراکسی WARP…'],
    ];

    public function __construct(private readonly ServerDeployment $deployment) {}

    public function append(string $type, string $chunk): void
    {
        if ($type !== 'err') return;
        foreach (explode("\n", $chunk) as $index => $part) {
            if ($index > 0) {
                if (!$this->discarding && preg_match('/^SPEEDBOX_WARP_PHASE:([a-z]+)\r?$/D', $this->pending, $match)
                    && isset(self::PHASES[$match[1]])) {
                    [$progress, $message] = self::PHASES[$match[1]];
                    if ($progress >= (int) $this->deployment->progress && $this->deployment->log !== $message) $this->deployment->update(['progress' => $progress, 'log' => $message]);
                }
                $this->pending = ''; $this->discarding = false;
            }
            if (!$this->discarding) {
                if (strlen($this->pending) + strlen($part) > 128) { $this->pending = ''; $this->discarding = true; }
                else $this->pending .= $part;
            }
        }
    }
}
