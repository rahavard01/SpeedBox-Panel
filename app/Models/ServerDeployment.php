<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerDeployment extends Model
{
    protected $table = 'speedbox_deployments';
    protected $guarded = ['id'];
    protected $casts = [
        'progress' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
