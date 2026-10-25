<?php
namespace Tests\Feature;

use App\Services\{AccountTariffResolver,PanelAccountActionService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Tests\TestCase;

class AccountTopupParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('panel_users',function(Blueprint $t){$t->id();$t->integer('role');$t->string('code');$t->string('credit');$t->string('partner_plan_type');$t->boolean('enable_personalized_price')->default(false);});
        Schema::create('panel_tariff_groups',function(Blueprint $t){$t->id();$t->string('name');$t->string('group_type');$t->boolean('enable');$t->boolean('is_default');$t->integer('sort_order')->default(0);$t->integer('min_traffic_gb')->default(10);$t->string('partner_access_mode');});
        Schema::create('panel_tariff_group_partner_access',function(Blueprint $t){$t->integer('tariff_group_id');$t->integer('partner_id');});
        Schema::create('panel_plan',function(Blueprint $t){$t->id();$t->integer('tariff_group_id');$t->integer('v2_plan_id')->nullable();$t->string('plan_key');$t->boolean('enable');$t->string('default_price');});
        Schema::create('v2_user',function(Blueprint $t){$t->id();$t->string('email');$t->integer('plan_id');$t->integer('account_tariff_group_id')->nullable();$t->string('account_group_type')->nullable();$t->string('account_plan_key')->nullable();$t->bigInteger('u')->default(0);$t->bigInteger('d')->default(0);$t->bigInteger('transfer_enable')->default(100000000000);$t->bigInteger('expired_at')->nullable();$t->string('account_plan_name')->nullable();$t->integer('device_limit')->nullable();$t->integer('group_id')->nullable();$t->integer('speed_limit')->nullable();$t->integer('traffic_warning_version')->default(0);$t->integer('updated_at')->nullable();});
        Schema::create('v2_plan',function(Blueprint $t){$t->id();$t->integer('device_limit')->nullable();$t->integer('group_id')->nullable();$t->integer('speed_limit')->nullable();});
        Schema::create('panel_transactions',function(Blueprint $t){$t->id();foreach(['panel_user_id','quantity','reference_id','performed_by_id','performed_by_role'] as $c)$t->integer($c)->nullable();foreach(['type','direction','amount','balance_before','balance_after','currency','status','plan_key_after','plan_key_before','reference_type','idempotency_key'] as $c)$t->string($c)->nullable();$t->text('meta')->nullable();$t->timestamps();});
        DB::table('panel_users')->insert(['id'=>1,'role'=>2,'code'=>'rep','credit'=>'10000','partner_plan_type'=>'time']);
        DB::table('panel_tariff_groups')->insert([
            ['id'=>1,'name'=>'Default, inaccessible','group_type'=>'time','enable'=>1,'is_default'=>1,'partner_access_mode'=>'selected_partners'],
            ['id'=>2,'name'=>'VIP','group_type'=>'time','enable'=>1,'is_default'=>0,'partner_access_mode'=>'selected_partners'],
        ]);
        DB::table('panel_tariff_group_partner_access')->insert(['tariff_group_id'=>2,'partner_id'=>1]);
        DB::table('panel_plan')->insert([
            ['tariff_group_id'=>1,'v2_plan_id'=>10,'plan_key'=>'1m','enable'=>1,'default_price'=>'2000'],
            ['tariff_group_id'=>1,'v2_plan_id'=>null,'plan_key'=>'gig','enable'=>1,'default_price'=>'500'],
            ['tariff_group_id'=>2,'v2_plan_id'=>20,'plan_key'=>'1m','enable'=>1,'default_price'=>'1000'],
            ['tariff_group_id'=>2,'v2_plan_id'=>null,'plan_key'=>'gig','enable'=>1,'default_price'=>'100'],
        ]);
        DB::table('v2_user')->insert(['id'=>1,'email'=>'alice@rep.com','plan_id'=>20,'account_tariff_group_id'=>2,'account_group_type'=>'time','account_plan_key'=>'1m','expired_at'=>time()+86400]);
    }

    private function topup(?int $group=null): array
    {
        return app(PanelAccountActionService::class)->topupTrafficForPanelUser(DB::table('panel_users')->first(),'alice@rep.com',10,$group,'telegram_bot');
    }

    public function test_bot_uses_account_group_and_correct_price_without_a_group_argument():void
    {
        $r=$this->topup();$this->assertTrue($r['ok'],json_encode($r));
        $this->assertSame('9000',DB::table('panel_users')->value('credit'));
        $this->assertSame(-10*1073741824,(int)DB::table('v2_user')->value('d'));
        $tx=DB::table('panel_transactions')->first();$this->assertSame('success',$tx->status);
        $this->assertSame(2,json_decode($tx->meta,true)['tariff_group_id']);
    }

    public function test_time_topup_cannot_be_repriced_by_supplying_a_different_group():void
    {
        $r=$this->topup(1);$this->assertTrue($r['ok'],json_encode($r));
        $this->assertSame('9000',DB::table('panel_users')->value('credit'));
    }

    public function test_real_access_restriction_is_preserved_without_charging():void
    {
        DB::table('panel_tariff_group_partner_access')->delete();
        $this->assertFalse($this->topup()['ok']);
        $this->assertSame('10000',DB::table('panel_users')->value('credit'));
        $this->assertSame(0,DB::table('panel_transactions')->count());
    }

    public function test_insufficient_balance_rolls_back_traffic_and_transactions():void
    {
        DB::table('panel_users')->update(['credit'=>'1']);
        $r=$this->topup();$this->assertFalse($r['ok']);$this->assertTrue($r['insufficient_credit']);
        $this->assertSame('1',DB::table('panel_users')->value('credit'));
        $this->assertSame(0,(int)DB::table('v2_user')->value('d'));
        $this->assertSame(0,DB::table('panel_transactions')->count());
    }

    public function test_unique_legacy_mapping_is_resolved_but_ambiguous_mapping_is_rejected():void
    {
        DB::table('v2_user')->update(['account_tariff_group_id'=>null]);
        $this->assertTrue($this->topup()['ok']);
        DB::table('panel_plan')->insert(['tariff_group_id'=>1,'v2_plan_id'=>20,'plan_key'=>'1m','enable'=>1,'default_price'=>'2000']);
        $this->assertFalse($this->topup()['ok']);
        $this->assertSame('9000',DB::table('panel_users')->value('credit'));
    }

    public function test_expired_volume_topup_keeps_existing_reset_and_expiry_contract():void
    {
        DB::table('panel_users')->update(['partner_plan_type'=>'both']);
        DB::table('panel_tariff_groups')->insert(['id'=>3,'name'=>'Volume','group_type'=>'volume','enable'=>1,'is_default'=>0,'partner_access_mode'=>'all_partners']);
        DB::table('panel_plan')->insert(['tariff_group_id'=>3,'v2_plan_id'=>30,'plan_key'=>'volume_gig','enable'=>1,'default_price'=>'100']);
        DB::table('v2_plan')->insert(['id'=>30,'device_limit'=>2,'group_id'=>1,'speed_limit'=>0]);
        DB::table('v2_user')->update(['plan_id'=>30,'account_tariff_group_id'=>3,'account_group_type'=>'volume','account_plan_key'=>'volume_gig','expired_at'=>time()-1,'u'=>100,'d'=>200]);
        $r=$this->topup();$this->assertTrue($r['ok'],json_encode($r));
        $account=DB::table('v2_user')->first();$this->assertSame(0,(int)$account->u);$this->assertSame(0,(int)$account->d);
        $this->assertSame(10*1073741824,(int)$account->transfer_enable);
        $this->assertEqualsWithDelta(time()+30*86400,(int)$account->expired_at,3);
        $this->assertSame('9000',DB::table('panel_users')->value('credit'));
    }

    public function test_admin_uses_same_group_with_a_zero_amount_transaction():void
    {
        DB::table('panel_users')->update(['role'=>1]);
        $r=$this->topup();$this->assertTrue($r['ok'],json_encode($r));
        $this->assertSame('10000',DB::table('panel_users')->value('credit'));
        $tx=DB::table('panel_transactions')->first();$this->assertSame('0',$tx->amount);
        $this->assertSame(2,json_decode($tx->meta,true)['tariff_group_id']);
    }
}
