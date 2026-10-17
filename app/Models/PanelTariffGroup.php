<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelTariffGroup extends Model
{
    protected $table = 'panel_tariff_groups';

    protected $fillable = [
        'name',
        'is_default',
        'group_type',
        'enable',
        'sort_order',
        'min_traffic_gb',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'enable' => 'boolean',
        'sort_order' => 'integer',
        'min_traffic_gb' => 'integer',
    ];
}