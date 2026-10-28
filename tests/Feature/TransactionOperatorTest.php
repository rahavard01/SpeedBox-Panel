<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\PanelController;
use App\Services\TransactionLogger;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TransactionOperatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('panel_users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('code');
        });
        Schema::create('v2_user', function (Blueprint $table) {
            $table->id();
            $table->string('email');
        });
        Schema::create('panel_transactions', function (Blueprint $table) {
            $table->id();
            foreach (['panel_user_id', 'quantity', 'reference_id', 'performed_by_id'] as $column) $table->integer($column)->nullable();
            foreach (['type', 'direction', 'amount', 'balance_before', 'balance_after', 'currency', 'status',
                'plan_key_after', 'plan_key_before', 'reference_type', 'idempotency_key', 'performed_by_role'] as $column) {
                $table->string($column)->nullable();
            }
            $table->text('meta')->nullable();
            $table->timestamps();
        });
        DB::connection()->getPdo()->sqliteCreateFunction('JSON_CONTAINS', static function ($document, $candidate, $path) {
            $meta = json_decode((string) $document, true);
            return (int) in_array(json_decode($candidate, true), $meta['user_ids'] ?? [], true);
        }, 3);
        DB::table('panel_users')->insert(['id' => 1, 'email' => 'rep@example.test', 'code' => 'rep']);
        DB::table('v2_user')->insert(['id' => 100, 'email' => 'alice@rep.com']);
    }

    private function transaction(array $extra = []): int
    {
        return app(TransactionLogger::class)->start(array_replace([
            'panel_user_id' => 1, 'type' => 'traffic_purchase', 'direction' => 'debit', 'amount' => '100',
            'balance_before' => '1000', 'balance_after' => '900', 'reference_type' => 'v2_user', 'reference_id' => 100,
        ], $extra));
    }

    public function test_completion_and_failure_preserve_the_original_actor_without_a_web_session(): void
    {
        $logger = app(TransactionLogger::class);
        foreach (['success', 'failed'] as $outcome) {
            $id = $this->transaction(['performed_by_id' => 7, 'performed_by_role' => 2]);
            if ($outcome === 'success') $logger->finalize($id);
            else $logger->fail($id, 'Fixture failure');
            $row = DB::table('panel_transactions')->find($id);
            $this->assertSame(7, (int) $row->performed_by_id);
            $this->assertSame('2', $row->performed_by_role);
            $this->assertSame($outcome, $row->status);
        }
    }

    public function test_a_different_finishing_request_does_not_replace_the_actor_but_an_explicit_override_does(): void
    {
        $logger = app(TransactionLogger::class);
        $id = $this->transaction(['performed_by_id' => 7, 'performed_by_role' => 2]);
        auth()->setUser(new GenericUser(['id' => 99, 'role' => 1]));
        $logger->finalize($id);
        $this->assertSame(7, (int) DB::table('panel_transactions')->find($id)->performed_by_id);
        $this->assertSame('2', DB::table('panel_transactions')->find($id)->performed_by_role);
        $logger->finalize($id, ['performed_by_id' => 12, 'performed_by_role' => 3]);
        $this->assertSame(12, (int) DB::table('panel_transactions')->find($id)->performed_by_id);
        $this->assertSame('3', DB::table('panel_transactions')->find($id)->performed_by_role);
    }

    public function test_missing_historical_identity_is_not_invented_by_the_finishing_request(): void
    {
        $id = $this->transaction();
        auth()->setUser(new GenericUser(['id' => 99, 'role' => 1]));
        app(TransactionLogger::class)->finalize($id);
        $row = DB::table('panel_transactions')->find($id);
        $this->assertNull($row->performed_by_id);
        $this->assertNull($row->performed_by_role);
    }

    public function test_both_histories_and_both_billing_views_agree_for_legacy_bot_human_system_and_unknown_records(): void
    {
        $expected = [];
        foreach ([
            [null, ['operator_source' => 'telegram_bot'], 'telegram_bot'],
            ['system', ['operator_source' => 'telegram_bot'], 'telegram_bot'],
            [1, [], '1'], [2, [], '2'], [3, [], '3'], [4, [], '4'],
            ['system', [], 'system'], [null, [], 'unknown'],
        ] as [$role, $meta, $display]) {
            // A bulk record exercises JSON user_ids rather than only the direct reference.
            $id = $this->transaction(['performed_by_role' => $role, 'reference_type' => null, 'reference_id' => null,
                'meta' => $meta + ['user_ids' => [100]]]);
            $expected[$id] = $display;
        }
        $user = new GenericUser(['id' => 1, 'role' => 2, 'code' => 'rep', 'email' => 'rep@example.test']);
        $request = Request::create('/fixture', 'GET', ['email' => 'alice@rep.com']);
        $request->setUserResolver(static fn () => $user);
        $responses = [
            app(PanelController::class)->transactionRecords($request)->getData(true)['data'],
            app(UserAdminController::class)->transactionRecords($request)->getData(true)['data'],
            app(BillingController::class)->self($request)->getData(true),
            app(BillingController::class)->all($request)->getData(true),
        ];
        foreach ($responses as $rows) {
            $this->assertCount(count($expected), $rows);
            foreach ($rows as $row) {
                $this->assertSame($expected[$row['id']], (string) $row['performed_by_role']);
                if (array_key_exists('operator', $row)) $this->assertSame($expected[$row['id']], (string) $row['operator']);
            }
        }
        // Reading old records must not rewrite their original audit data.
        $this->assertSame(2, DB::table('panel_transactions')->where('performed_by_role', 'system')->count());
        $this->assertSame(2, DB::table('panel_transactions')->whereNull('performed_by_role')->count());
    }
}
