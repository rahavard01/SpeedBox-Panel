<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\PartnersAdminController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PartnerProfileRoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('panel_users', function (Blueprint $table) {
            $table->id(); $table->integer('role'); $table->boolean('banned')->default(false);
            $table->string('name'); $table->string('email')->unique(); $table->string('password')->nullable();
            $table->string('code')->unique(); $table->boolean('partner_code_locked')->default(true);
            $table->unsignedBigInteger('referred_by_id')->nullable(); $table->integer('ref_commission_rate')->nullable();
            $table->string('partner_plan_type')->default('both'); $table->bigInteger('credit')->default(1000000);
            $table->timestamps();
        });
        DB::table('panel_users')->insert([
            ['id' => 1, 'role' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'code' => 'admin'],
            ['id' => 2, 'role' => 2, 'name' => 'Rep', 'email' => 'rep@example.test', 'code' => 'rep'],
        ]);
        Route::patch('/fixture/partners/{id}', [PartnersAdminController::class, 'updateProfile'])->middleware('can:admin');
        $this->actingAs(User::findOrFail(1));
    }

    public function test_existing_representative_can_be_promoted_and_demoted(): void
    {
        $this->patchJson('/fixture/partners/2', ['role' => 1])->assertOk()->assertJsonPath('changed.0', 'role');
        $this->assertSame(1, (int) DB::table('panel_users')->where('id', 2)->value('role'));
        $this->patchJson('/fixture/partners/2', ['role' => '2'])->assertOk();
        $user = DB::table('panel_users')->where('id', 2)->first();
        $this->assertSame(2, (int) $user->role); $this->assertSame('rep', $user->code);
        $this->assertSame(1000000, (int) $user->credit); $this->assertTrue((bool) $user->partner_code_locked);
    }

    public function test_last_active_admin_cannot_be_demoted(): void
    {
        $this->patchJson('/fixture/partners/1', ['role' => 2])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertSame(1, (int) DB::table('panel_users')->where('id', 1)->value('role'));
        DB::table('panel_users')->where('id', 2)->update(['role' => 1, 'banned' => true]);
        $this->patchJson('/fixture/partners/1', ['role' => 2])->assertUnprocessable();
    }

    public function test_admin_can_be_demoted_when_another_active_admin_exists(): void
    {
        DB::table('panel_users')->where('id', 2)->update(['role' => 1]);
        $this->patchJson('/fixture/partners/2', ['role' => 2])->assertOk();
    }

    public function test_invalid_roles_and_locked_panel_code_are_rejected_atomically(): void
    {
        $this->patchJson('/fixture/partners/2', ['role' => 3])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->patchJson('/fixture/partners/2', ['role' => null])->assertUnprocessable();
        $this->patchJson('/fixture/partners/2', ['role' => 1, 'panel_code' => 'newcode'])->assertUnprocessable();
        $this->assertSame(2, (int) DB::table('panel_users')->where('id', 2)->value('role'));
    }

    public function test_profile_edits_without_role_preserve_it(): void
    {
        $this->patchJson('/fixture/partners/2', ['name' => 'New name'])->assertOk();
        $this->assertSame(2, (int) DB::table('panel_users')->where('id', 2)->value('role'));
    }

    public function test_representative_cannot_change_their_own_role(): void
    {
        $this->actingAs(User::findOrFail(2));
        $this->patchJson('/fixture/partners/2', ['role' => 1])->assertForbidden();
        $this->assertSame(2, (int) DB::table('panel_users')->where('id', 2)->value('role'));
    }
}
