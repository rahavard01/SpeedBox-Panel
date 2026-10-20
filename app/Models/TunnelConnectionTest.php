<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TunnelConnectionTest extends Model
{
    protected $table = 'speedbox_tunnel_tests';
    protected $guarded = ['id'];
    protected $casts = ['results' => 'array', 'compare_both' => 'boolean'];
    public function operations() { return $this->hasMany(TunnelOperation::class, 'test_id'); }
}
