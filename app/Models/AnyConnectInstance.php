<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnyConnectInstance extends Model
{
    protected $table = 'speedbox_anyconnect_instances';
    protected $guarded = ['id'];
    protected $hidden = ['owner_id', 'link_keys', 'radius_secret', 'certificate_config'];
    protected $appends = ['certificate'];
    protected $casts = [
        'dns' => 'array', 'enabled' => 'boolean', 'last_synced_at' => 'datetime',
        'link_keys' => 'encrypted:array', 'radius_secret' => 'encrypted', 'certificate_config' => 'encrypted:array',
    ];

    public function getCertificateAttribute(): array
    {
        $config = $this->certificate_config ?? [];
        return [
            'mode' => $config['mode'] ?? 'existing',
            'dns_provider' => $config['dns_provider'] ?? null,
            'email' => $config['email'] ?? '',
            'http_port' => $config['http_port'] ?? 80,
            'saved_dns_fields' => array_keys($config['dns_env'] ?? []),
        ];
    }

    public function server()
    {
        return $this->belongsTo(ManagedServer::class, 'managed_server_id');
    }

    public function ingress()
    {
        return $this->belongsTo(ManagedServer::class, 'ingress_server_id');
    }

    public function accounts()
    {
        return $this->hasMany(AnyConnectAccount::class, 'instance_id');
    }

    public function address(): string
    {
        return $this->endpoint_host.($this->endpoint_port == 443 ? '' : ':'.$this->endpoint_port);
    }

    public function isReady(): bool
    {
        return $this->enabled && $this->status === 'ready'
            && $this->last_synced_at?->gt(now()->subMinutes(3));
    }
}
