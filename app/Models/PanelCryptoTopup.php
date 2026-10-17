<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelCryptoTopup extends Model
{
    protected $table = 'panel_crypto_topups';

    protected $fillable = [
        'user_id',
        'amount_irt',
        'asset',
        'network',
        'wallet_address',
        'crypto_amount',
        'base_crypto_amount',
        'unique_extra',
        'status',
        'tx_hash',
        'bsc_start_block',
        'bsc_last_checked_block',
        'expires_at',
        'paid_at',
        'meta',
        'notified_at',
        'telegram_notified_at',
    ];

    protected $casts = [
        'amount_irt' => 'integer',
        'crypto_amount' => 'decimal:8',
        'base_crypto_amount' => 'decimal:8',
        'unique_extra' => 'decimal:8',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'meta' => 'array',
        'notified_at' => 'datetime',
        'telegram_notified_at' => 'datetime',
        'bsc_start_block' => 'integer',
        'bsc_last_checked_block' => 'integer',
    ];
}