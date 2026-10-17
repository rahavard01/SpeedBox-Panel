<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBotSmsDevice extends Model
{
    protected $fillable = [
        'setting_id',
        'name',
        'platform',
        'token_hash',
        'is_enabled',
        'last_seen_at',
        'last_ip',
        'metadata',
    ];

    /*
     * Hash توکن در خروجی‌های مدل نمایش داده نشود.
     */
    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function setting()
    {
        return $this->belongsTo(
            UserBotSetting::class,
            'setting_id'
        );
    }

    public function events()
    {
        return $this->hasMany(
            UserBotSmsEvent::class,
            'device_id'
        );
    }
}