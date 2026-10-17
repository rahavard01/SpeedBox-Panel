<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelPlan extends Model
{
    protected $table = 'panel_plan';
    public $timestamps = false;

    protected $fillable = [
        'tariff_group_id',
        'name',
        'enable',
        'default_price',
        'v2_plan_id',
        'plan_key',
        'details',
    ];
}