<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBotSetting extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'bot_token',
        'webhook_secret',
        'owner_telegram_chat_id',
        'owner_connect_code',
        'active_users_only',
        'is_enabled',
        'card_payment_enabled',
        'card_ai_auto_approve_enabled',
        'card_sms_auto_approve_enabled',
        'card_sms_shortcut_url',
        'card_sms_android_app_url',
        'crypto_payment_enabled',
        'crypto_asset',
        'crypto_network',
        'crypto_wallet_address',
        'crypto_trongrid_api_key',
        'crypto_bscscan_api_key',
        'card_number',
        'card_holder',
        'card_description',
        'support_username',
        'force_channel_join_enabled',
        'force_channel_join_chat',
        'help_ios',
        'help_android',
        'help_windows',        
    ];

    protected $casts = [
        'active_users_only' => 'boolean',
        'is_enabled' => 'boolean',
        'force_channel_join_enabled' => 'boolean',
        'card_payment_enabled' => 'boolean',
        'card_ai_auto_approve_enabled' => 'boolean',
        'card_sms_auto_approve_enabled' => 'boolean',
        'crypto_payment_enabled' => 'boolean',
    ];

    public function plans()
    {
        return $this->hasMany(UserBotPlan::class, 'setting_id');
    }

    public function smsDevices()
    {
        return $this->hasMany(
            UserBotSmsDevice::class,
            'setting_id'
        );
    }

}