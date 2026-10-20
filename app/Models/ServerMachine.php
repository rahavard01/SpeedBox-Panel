<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServerMachine extends Model
{
    protected $table = 'v2_server_machine';
    protected $guarded = ['id'];
    protected $hidden = ['token'];
    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'integer',
        'load_status' => 'array',
        'warp_status' => 'array',
    ];

    public function nodes(): HasMany
    {
        return $this->hasMany(ProxyServer::class, 'machine_id');
    }

    public function supportsWarp(): bool
    {
        return in_array('warp-egress-v1', (array) data_get($this->load_status, 'capabilities', []), true);
    }

    public function managedServers(): HasMany
    {
        return $this->hasMany(ManagedServer::class, 'machine_id');
    }

    public function loadHistory(): HasMany
    {
        return $this->hasMany(ServerMachineLoadHistory::class, 'machine_id');
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }
}
