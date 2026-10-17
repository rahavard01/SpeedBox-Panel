<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerRoute extends Model
{
    protected $table = 'v2_server_route';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';
    protected $casts = ['match' => 'array'];
}
