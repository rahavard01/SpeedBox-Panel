<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelUserTariffPrice extends Model
{
    protected $table = 'panel_user_tariff_prices';

    protected $fillable = [
        'panel_user_id',
        'tariff_group_id',
        'plan_key',
        'price',
    ];
}