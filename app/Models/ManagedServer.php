<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedServer extends Model
{
    protected $table = 'speedbox_managed_servers';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'private_key', 'private_key_passphrase', 'reverse_ssh_port'];
    protected $casts = [
        'password' => 'encrypted',
        'private_key' => 'encrypted',
        'private_key_passphrase' => 'encrypted',
        'last_checked_at' => 'integer',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(ServerDeployment::class, 'managed_server_id');
    }
}
