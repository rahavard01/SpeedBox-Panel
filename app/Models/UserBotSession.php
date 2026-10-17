<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBotSession extends Model
{
    protected $fillable = [
        'setting_id',
        'telegram_chat_id',
        'telegram_user_id',
        'telegram_username',
        'is_logged_in',
        'account_email',
        'account_sub_link',
        'account_snapshot',
        'expiry_warning_for',
        'expiry_warning_account_id',
        'expiry_warning_sent_at',
        'traffic_warning_for',
        'traffic_warning_sent_at',
        'state',
        'state_data',
    ];

    protected $casts = [
        'is_logged_in' => 'boolean',
        'account_snapshot' => 'array',
        'expiry_warning_for' => 'integer',
        'expiry_warning_account_id' => 'integer',
        'expiry_warning_sent_at' => 'datetime',
        'traffic_warning_sent_at' => 'datetime',
        'state_data' => 'array',
    ];
}
