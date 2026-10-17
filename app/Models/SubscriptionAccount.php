<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** Token-authenticated subscription account; panel login still uses User. */
class SubscriptionAccount extends Authenticatable
{
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'password_algo', 'password_salt'];
    protected $casts = [
        'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        'banned' => 'boolean', 'is_admin' => 'boolean', 'is_staff' => 'boolean',
        'next_reset_at' => 'timestamp', 'last_reset_at' => 'timestamp',
    ];
}
