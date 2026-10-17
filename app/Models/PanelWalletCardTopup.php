<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelWalletCardTopup extends Model
{
    protected $table = 'panel_wallet_card_topups';

    protected $fillable = [
        'user_id',
        'amount_irt',
        'payable_amount_irt',
        'unique_extra',
        'sms_match_key',
        'status',
        'created_via',
        'card_number',
        'card_holder',
        'expires_at',
        'sms_paid_at',
        'paid_at',
        'approval_source',
        'transaction_id',
        'commission_paid',
        'commission_tx_id',
        'commission_notified_at',
        'notified_at',
        'meta',
    ];

    protected $casts = [
        'amount_irt' => 'integer',
        'payable_amount_irt' => 'integer',
        'unique_extra' => 'integer',
        'expires_at' => 'datetime',
        'sms_paid_at' => 'datetime',
        'paid_at' => 'datetime',
        'commission_paid' => 'boolean',
        'commission_notified_at' => 'datetime',
        'notified_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function receipts()
    {
        return $this->hasMany(
            PanelWalletReceipt::class,
            'card_topup_id'
        );
    }
}