<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TunnelOperation extends Model
{
    protected $table = 'speedbox_tunnel_operations';
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected $casts = ['payload' => 'encrypted:array', 'result' => 'array', 'cancel_requested' => 'boolean', 'deadline' => 'datetime'];
}
