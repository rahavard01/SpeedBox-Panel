<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CryptoPaymentOption extends Model
{
    protected $fillable = [
        'context',
        'owner_type',
        'owner_id',
        'asset',
        'network',
        'title',
        'wallet_address',
        'enabled',
        'sort_order',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'owner_id' => 'integer',
        'sort_order' => 'integer',
    ];
}