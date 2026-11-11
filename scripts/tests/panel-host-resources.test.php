<?php
namespace Illuminate\Support\Facades {
    class Cache {
        public static array $values = [];
        public static float $clock = 1000;
        public static bool $fail = false;
        public static function get($key) {
            if (self::$fail) throw new \RuntimeException('cache unavailable');
            $v = self::$values[$key] ?? null;
            return $v && $v[1] > self::$clock ? $v[0] : null;
        }
        public static function put($key, $value, $ttl) {
            if (self::$fail) throw new \RuntimeException('cache unavailable');
            self::$values[$key] = [$value, self::$clock + $ttl];
        }
    }
}
namespace {
    use Illuminate\Support\Facades\Cache;
    function base_path() { return dirname(__DIR__, 2); }
    require base_path().'/app/Services/PanelHostResources.php';
    class FixtureResources extends \App\Services\PanelHostResources {
        public array $files = [];
        public int $reads = 0;
        protected function read(string $name): string|false { $this->reads++; return $this->files[$name] ?? false; }
        protected function now(): float { return Cache::$clock; }
        protected function disk(): ?array { return ['total'=>10000, 'used'=>4000, 'percent'=>40.0]; }
    }
    $checks=0;
    function check(bool $value, string $message): void { global $checks; if (!$value) throw new \RuntimeException($message); $checks++; }
    $host = new FixtureResources();
    $host->files=['stat'=>"cpu 100 0 50 850 0 0 0 0 99999 99999\ncpu0 100 0 50 850\n", 'meminfo'=>"MemTotal: 1000 kB\nMemAvailable: 250 kB\nCached: 600 kB\n"];
    $first=$host->snapshot();
    check($first['cpu']===null,'first CPU sample is unknown, never a fabricated zero');
    check($first['memory']['used']===750*1024 && $first['memory']['percent']===75.0,'available memory excludes reclaimable cache pressure');
    $reads=$host->reads; $host->snapshot();
    check($host->reads===$reads,'warm snapshot is cached without rereading proc');
    Cache::$clock+=3;
    $host->files['stat']="cpu 150 0 100 900 0 0 0 0 199999 199999\n";
    $second=$host->snapshot();
    check($second['cpu']===66.7,'CPU uses tick deltas and excludes duplicated guest ticks');
    check($second['disk']['percent']===40.0,'disk data reaches the snapshot');
    Cache::$clock+=10; $reads=$host->reads;$host->snapshot();
    check($host->reads===$reads,'valid metrics share the 15-second cache');
    Cache::$clock+=6;
    $host->files['stat']="cpu 1 0 1 8 0 0 0 0\n";
    $host->files['meminfo']="MemTotal: 1000 kB\nMemFree: 100 kB\nBuffers: 50 kB\nCached: 100 kB\nSReclaimable: 50 kB\nShmem: 50 kB\n";
    $reset=$host->snapshot();
    check($reset['cpu']===null,'counter reset after reboot needs a new sample');
    check($reset['memory']['percent']===75.0,'fallback memory calculation includes reclaimable slab and excludes shared memory');
    Cache::$clock+=3;$host->files['meminfo']="MemTotal: 1000 kB\nMemAvailable: 0 kB\n";
    check($host->snapshot()['memory']['percent']===100.0,'zero available is a real value');
    Cache::$clock+=3;$host->files=['meminfo'=>"MemTotal: 0 kB\n"];
    $missing=$host->snapshot();
    check($missing['memory']===null && $missing['cpu']===null,'missing and invalid metrics stay unknown');
    Cache::$fail=true;$host->files=['meminfo'=>"MemTotal: 1000 kB\nMemAvailable: 250 kB\n"];
    check($host->snapshot()['memory']['percent']===75.0,'cache outage still permits reading memory and disk');
    echo "PANEL HOST RESOURCES: {$checks} checks passed\n";
}
