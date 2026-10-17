<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMachineLoadHistory extends Model
{
    protected $table = 'v2_server_machine_load_history';
    protected $guarded = ['id'];
    protected $casts = [
        'cpu' => 'float',
        'mem_total' => 'integer',
        'mem_used' => 'integer',
        'disk_total' => 'integer',
        'disk_used' => 'integer',
        'net_in_speed' => 'float',
        'net_out_speed' => 'float',
        'recorded_at' => 'integer',
    ];
}
