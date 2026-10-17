<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelCryptoWalletSetting extends Model
{
    protected $table = 'panel_crypto_wallet_settings';

    protected $fillable = [
        'asset',
        'network',
        'wallet_address',
        'trongrid_api_key',
        'bscscan_api_key',
    ];
}