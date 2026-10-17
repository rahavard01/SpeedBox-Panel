<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBotOrder extends Model
{
    protected $fillable = [
        'setting_id',
        'session_id',
        'order_type',
        'order_code',
        'plan_key',
        'account_email',
        'traffic_gb',
        'amount',
        'card_sms_payable_amount',
        'card_sms_unique_extra',
        'card_sms_expires_at',
        'card_sms_match_key',
        'card_sms_paid_at',
        'card_processing_token',
        'card_processing_source',
        'card_processing_started_at',
        'card_processing_attempts',
        'card_processing_error',
        'payment_method',
        'crypto_asset',
        'crypto_network',
        'crypto_wallet_address',
        'crypto_amount',
        'crypto_base_amount',
        'crypto_unique_extra',
        'crypto_tx_hash',
        'crypto_expires_at',
        'crypto_paid_at',
        'crypto_meta',
        'bsc_start_block',
        'bsc_last_checked_block',
        'status',
        'receipt_file_id',
        'receipt_file_unique_id',
        'receipt_submitted_at',
        'payload',
        'admin_note',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'traffic_gb' => 'integer',
        'amount' => 'integer',
        'card_sms_payable_amount' => 'integer',
        'card_sms_unique_extra' => 'integer',
        'card_sms_expires_at' => 'datetime',
        'card_sms_paid_at' => 'datetime',
        'card_processing_started_at' => 'datetime',
        'card_processing_attempts' => 'integer',
        'receipt_submitted_at' => 'datetime',
        'payload' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'crypto_amount' => 'decimal:6',
        'crypto_base_amount' => 'decimal:6',
        'crypto_unique_extra' => 'decimal:8',
        'crypto_expires_at' => 'datetime',
        'crypto_paid_at' => 'datetime',
        'crypto_meta' => 'array',
        'bsc_start_block' => 'integer',
        'bsc_last_checked_block' => 'integer',
    ];
}