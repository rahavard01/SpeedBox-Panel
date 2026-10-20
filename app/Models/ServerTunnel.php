<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerTunnel extends Model
{
    protected $table = 'speedbox_tunnels';
    protected $guarded = ['id'];
    protected $hidden = ['secret'];
    protected $casts = ['secret' => 'encrypted', 'settings' => 'array', 'remote_status' => 'array'];
    public function iran() { return $this->belongsTo(ManagedServer::class, 'iran_server_id'); }
    public function foreign() { return $this->belongsTo(ManagedServer::class, 'foreign_server_id'); }
    public function operations() { return $this->hasMany(TunnelOperation::class, 'tunnel_id'); }
}
