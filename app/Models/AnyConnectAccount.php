<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AnyConnectAccount extends Model
{
    protected $table = 'speedbox_anyconnect_accounts';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['password', 'password_hash'];
    protected $casts = ['password' => 'encrypted'];
}
