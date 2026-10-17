<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmergencyConfig extends Model
{
    protected $table = 'speedbox_emergency_configs';
    protected $guarded = ['id'];
    protected $hidden = ['config_uri'];
    protected $casts = ['config_uri' => 'encrypted', 'show' => 'boolean', 'sort' => 'integer', 'port' => 'integer'];
}
