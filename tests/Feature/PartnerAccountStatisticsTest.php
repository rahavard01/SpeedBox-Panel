<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartnerAccountStatisticsTest extends TestCase
{
    private User $admin;
    private string $endpoint = '/api/admin/partners/account-statistics';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('panel_users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('code')->nullable();
            $t->string('password'); $t->integer('role'); $t->boolean('banned')->default(false); $t->timestamps();
        });
        (require database_path('migrations/2025_01_01_000000_create_speedbox_core_tables.php'))->up();
        (require database_path('migrations/2026_10_10_000003_create_connection_admission_leases.php'))->up();
        $this->admin = $this->person('admin', 1);
        $this->withToken($this->admin->createToken('test')->plainTextToken);
    }

    private function person(string $code, int $role = 2): User
    {
        $user = new User();
        $user->forceFill(['name'=>$code, 'code'=>$code, 'email'=>$code.'@panel.test', 'password'=>'password', 'role'=>$role, 'banned'=>false])->save();
        return $user;
    }

    private function account(string $email, array $overrides = []): int
    {
        return DB::table('v2_user')->insertGetId(array_merge([
            'email'=>$email, 'password'=>'password', 'uuid'=>(string) \Illuminate\Support\Str::uuid(),
            'token'=>\Illuminate\Support\Str::random(32), 'transfer_enable'=>1000, 'u'=>10, 'd'=>20,
            'banned'=>false, 'expired_at'=>time()+3600, 'created_at'=>time(), 'updated_at'=>time(),
        ], $overrides));
    }

    private function lease(int $userId, int $nodeId, string $ip, int $expires): void
    {
        DB::table('speedbox_connection_leases')->insert(['user_id'=>$userId, 'node_id'=>$nodeId, 'source_ip'=>$ip, 'lease_id'=>\Illuminate\Support\Str::random(32), 'expires_at'=>$expires]);
    }

    public function test_counts_owned_accounts_and_distinct_live_users(): void
    {
        $this->person('Alpha');
        $first = $this->account('alpha@one.test');
        $second = $this->account('two@ALPHA.test', ['expired_at'=>null]);
        $this->account('three@alpha', ['expired_at'=>0, 'u'=>-500, 'd'=>1000]);
        $this->account('four@alpha.test', ['expired_at'=>time()-10]);
        $this->account('five@alpha.test', ['u'=>500, 'd'=>500]);
        $this->account('six@alpha.test', ['banned'=>true]);
        $this->account('seven@alpha.test', ['transfer_enable'=>0]);
        $foreign = $this->account('other@alphabeta.test');
        $this->lease($first, 1, '1.1.1.1', time()+100);
        $this->lease($first, 2, '1.1.1.1', time()+100);
        $this->lease($first, 1, '2.2.2.2', time()+100);
        $this->lease($second, 1, '1.1.1.1', time()+100);
        $this->lease($second, 2, '3.3.3.3', time()-100);
        $this->lease($foreign, 1, '4.4.4.4', time()+100);
        $this->getJson($this->endpoint.'?panel_code=%20ALPHA%20')->assertOk()
            ->assertExactJson(['data'=>['total'=>7,'active'=>3,'online'=>2,'expired'=>1]])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_empty_partner_and_missing_leases_return_zero_counts(): void
    {
        $this->person('empty');
        $this->getJson($this->endpoint.'?panel_code=empty')->assertOk()->assertExactJson(['data'=>['total'=>0,'active'=>0,'online'=>0,'expired'=>0]]);
        Schema::drop('speedbox_connection_leases');
        $this->account('empty@one.test');
        $this->getJson($this->endpoint.'?panel_code=empty')->assertOk()->assertJsonPath('data.total',1)->assertJsonPath('data.online',0);
    }

    public function test_codes_with_wildcards_or_domains_do_not_capture_other_partners(): void
    {
        $this->person('a_%');
        $this->account('a_%@one.test');
        $this->account('two@a_%.test');
        $this->account('other@abcd.test');
        $this->getJson($this->endpoint.'?'.http_build_query(['panel_code'=>'a_%']))->assertOk()->assertJsonPath('data.total',2);
        $this->person('alpha.com');
        $this->account('alpha.com@one.test');
        $this->account('other@alpha.com');
        $this->getJson($this->endpoint.'?panel_code=alpha.com')->assertOk()->assertJsonPath('data.total',1);
    }

    public function test_expired_leases_and_recent_timestamps_do_not_count_as_online(): void
    {
        $this->person('alpha');
        $id = $this->account('alpha@one.test', ['t'=>time()]);
        $this->lease($id, 1, '1.1.1.1', time()-1);
        $this->getJson($this->endpoint.'?panel_code=alpha')->assertOk()->assertJsonPath('data.online',0);
        $this->lease($id, 2, '1.1.1.1', time()+100);
        $this->getJson($this->endpoint.'?panel_code=alpha')->assertOk()->assertJsonPath('data.online',1);
    }

    public function test_validation_and_admin_authorization(): void
    {
        $this->getJson($this->endpoint)->assertUnprocessable();
        $this->getJson($this->endpoint.'?panel_code[]=alpha')->assertUnprocessable();
        $this->getJson($this->endpoint.'?panel_code=unknown')->assertNotFound();
        $partner = $this->person('alpha');
        $this->withToken($partner->createToken('test')->plainTextToken);
        $this->app['auth']->forgetGuards();
        $this->getJson($this->endpoint.'?panel_code=alpha')->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->endpoint.'?panel_code=alpha')->assertUnauthorized();
    }
}
