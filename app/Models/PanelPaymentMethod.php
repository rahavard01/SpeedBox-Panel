<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelPaymentMethod extends Model
{
    protected $table = 'panel_payment_methods';

    protected $fillable = [
        'method',
        'enabled',
        'access_mode',
        'partner_ids',
        'ai_auto_approve_enabled',
        'sms_auto_approve_enabled',
        'gemini_api_key',
        'ai_receipt_check_fee',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'partner_ids' => 'array',
        'ai_auto_approve_enabled' => 'boolean',
        'sms_auto_approve_enabled' => 'boolean',
        'ai_receipt_check_fee' => 'integer',
    ];
}