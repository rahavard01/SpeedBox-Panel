<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WireGuardPeer extends Model {
    protected $table='speedbox_wireguard_peers';
    protected $guarded=['id'];
    protected $attributes=['enabled'=>true];
    protected $hidden=['private_key','preshared_key'];
    protected $casts=['private_key'=>'encrypted','preshared_key'=>'encrypted','enabled'=>'boolean','retired_keys'=>'array'];
}
