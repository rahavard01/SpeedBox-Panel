<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyServer extends Model
{
    protected static function booted(): void
    {
        $schedule=function(self $node): void {
            if(!\Illuminate\Support\Facades\Schema::hasTable('speedbox_tunnels'))return;
            $machines=array_values(array_unique(array_filter([(int)$node->getOriginal('machine_id'),(int)$node->machine_id])));
            if($machines)\App\Jobs\SyncTunnelOrigins::dispatch($machines)->afterCommit();
        };
        static::saved(function(self $node)use($schedule){
            if($node->wasRecentlyCreated||$node->wasChanged(['machine_id','server_port','parent_id','enabled','type']))$schedule($node);
        });
        static::deleted($schedule);
    }

    public const TYPE_VMESS = 'vmess';
    public const TYPE_VLESS = 'vless';
    public const TYPE_TROJAN = 'trojan';
    public const TYPE_SHADOWSOCKS = 'shadowsocks';
    public const TYPE_HYSTERIA = 'hysteria';
    public const TYPE_TUIC = 'tuic';
    public const TYPE_ANYTLS = 'anytls';
    public const TYPE_SOCKS = 'socks';
    public const TYPE_HTTP = 'http';
    public const TYPE_NAIVE = 'naive';
    public const TYPE_MIERU = 'mieru';

    public const TYPES = ['vmess', 'vless', 'trojan', 'shadowsocks', 'hysteria', 'tuic', 'anytls', 'socks', 'http', 'naive', 'mieru'];
    public const KERNELS = ['singbox', 'xray'];

    protected $table = 'v2_server';
    protected $guarded = ['id'];
    protected $casts = [
        'group_ids' => 'array',
        'route_ids' => 'array',
        'tags' => 'array',
        'protocol_settings' => 'array',
        'custom_outbounds' => 'array',
        'custom_routes' => 'array',
        'cert_config' => 'array',
        'rate_time_ranges' => 'array',
        'show' => 'boolean',
        'enabled' => 'boolean',
        'warp_enabled' => 'boolean',
        'rate_time_enable' => 'boolean',
        'machine_id' => 'integer',
        'u' => 'integer',
        'd' => 'integer',
        'transfer_enable' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function runtimeNode(): self
    {
        return $this->parent_id ? ($this->parent ?? $this) : $this;
    }

    public function effectiveRate(): float
    {
        $node = $this->runtimeNode();
        $time = now()->format('H:i');
        if ($node->rate_time_enable) {
            foreach ($node->rate_time_ranges ?? [] as $range) {
                if ($time >= ($range['start'] ?? '00:00') && $time <= ($range['end'] ?? '23:59')) return max(0, (float) ($range['rate'] ?? $node->rate));
            }
        }
        return max(0, (float) $node->rate);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }
}

