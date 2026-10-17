<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBotSmsEvent extends Model
{
    protected $fillable = [
        'device_id',
        'setting_id',
        'event_id',
        'platform',
        'local_filter_version',
        'candidate_count',
        'status',
        'matched_order_id',
        'matched_card_topup_id',
        'matched_amount_toman',
        'matched_numeric_value',
        'failure_reason',
        'received_at_device',
        'received_at_server',
        'request_ip',
    ];

    protected $casts = [
        'candidate_count' => 'integer',
        'matched_order_id' => 'integer',
        'matched_card_topup_id' => 'integer',
        'matched_amount_toman' => 'integer',
        'matched_numeric_value' => 'integer',
        'received_at_device' => 'datetime',
        'received_at_server' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(
            UserBotSmsDevice::class,
            'device_id'
        );
    }

    public function setting()
    {
        return $this->belongsTo(
            UserBotSetting::class,
            'setting_id'
        );
    }

    public function matchedOrder()
    {
        return $this->belongsTo(
            UserBotOrder::class,
            'matched_order_id'
        );
    }

    public function matchedCardTopup()
    {
        return $this->belongsTo(
            PanelWalletCardTopup::class,
            'matched_card_topup_id'
        );
    }    
}