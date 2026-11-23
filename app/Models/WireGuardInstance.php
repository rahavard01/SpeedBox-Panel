<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WireGuardInstance extends Model {
    protected $table='speedbox_wireguard_instances';
    protected $guarded=['id'];
    protected $hidden=['owner_id','site_keys'];
    protected static function booted():void { static::creating(function($model){if(!$model->owner_id)$model->owner_id=(string)\Illuminate\Support\Str::uuid();}); }
    protected $casts=['dns'=>'array','enabled'=>'boolean','last_synced_at'=>'datetime','site_keys'=>'encrypted:array','auto_network'=>'boolean','auto_link_network'=>'boolean'];
    public function server() { return $this->belongsTo(ManagedServer::class,'managed_server_id'); }
    public function ingress() { return $this->belongsTo(ManagedServer::class,'ingress_server_id'); }
    public function peers() { return $this->hasMany(WireGuardPeer::class,'instance_id'); }
}
