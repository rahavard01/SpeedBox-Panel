<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class PanelHostResources
{
    public function snapshot(): array
    {
        $key = 'speedbox.panel-resources.'.sha1((gethostname() ?: 'host').'|'.base_path());
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) return $cached;
        } catch (\Throwable) {
            // A monitoring failure must not take the dashboard down.
        }

        $now = $this->now();
        $cpu = null;
        $stat = $this->read('stat');
        if (is_string($stat) && preg_match('/^cpu[ \t]+([0-9 \t]+)$/m', $stat, $match)) {
            $ticks = array_map('intval', preg_split('/\s+/', trim($match[1])));
            if (count($ticks) >= 4) {
                // Guest time is already included in user/nice; count the first eight fields only.
                $sample = ['total' => array_sum(array_slice($ticks, 0, 8)), 'idle' => $ticks[3] + ($ticks[4] ?? 0), 'at' => $now];
                try {
                    $previous = Cache::get($key.'.cpu');
                    if (is_array($previous) && $now - $previous['at'] >= 0.25 && $now - $previous['at'] <= 120) {
                        $total = $sample['total'] - $previous['total'];
                        $idle = $sample['idle'] - $previous['idle'];
                        if ($total > 0 && $idle >= 0 && $idle <= $total) $cpu = round(100 * ($total - $idle) / $total, 1);
                    }
                    Cache::put($key.'.cpu', $sample, 120);
                } catch (\Throwable) {}
            }
        }

        $memory = null;
        $info = $this->read('meminfo');
        if (is_string($info)) {
            preg_match_all('/^(\w+):\s+(\d+)\s+kB$/m', $info, $matches, PREG_SET_ORDER);
            $values = [];
            foreach ($matches as $row) $values[$row[1]] = (int) $row[2] * 1024;
            $total = $values['MemTotal'] ?? 0;
            $available = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0) + ($values['SReclaimable'] ?? 0) - ($values['Shmem'] ?? 0));
            if ($total > 0) $memory = $this->usage($total, $available);
        }
        $snapshot = ['sampled_at' => (int) $now, 'cpu' => $cpu, 'memory' => $memory, 'disk' => $this->disk()];
        try { Cache::put($key, $snapshot, $cpu === null ? 2 : 15); } catch (\Throwable) {}
        return $snapshot;
    }

    protected function now(): float { return microtime(true); }
    protected function read(string $name): string|false { return @file_get_contents('/proc/'.$name); }
    protected function disk(): ?array
    {
        $total = @disk_total_space(base_path());
        $free = @disk_free_space(base_path());
        return $total !== false && $total > 0 && $free !== false ? $this->usage($total, $free) : null;
    }
    private function usage(float $total, float $available): array
    {
        $used = max(0, min($total, $total - $available));
        return ['total' => (int) $total, 'used' => (int) $used, 'percent' => round(100 * $used / $total, 1)];
    }
}
