<?php

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PanelApiRequestLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->get('/api/request-limit-fixture', fn () => response()->json(['ok' => true]));
        Route::middleware('api')->post('/api/request-limit-fixture', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'throttle:1,1'])->post('/api/sensitive-limit-fixture', fn () => response()->json(['ok' => true]));
    }

    public function test_normal_authenticated_reads_and_writes_have_no_general_sixty_request_ceiling(): void
    {
        $this->actingAs(new GenericUser(['id' => 9101]));
        for ($i = 0; $i < 75; $i++) {
            $this->getJson('/api/request-limit-fixture')->assertOk();
            $this->postJson('/api/request-limit-fixture')->assertOk();
        }
    }

    public function test_the_old_general_setting_cannot_reintroduce_the_ceiling(): void
    {
        config(['speedbox.api_rate_limit' => 1]);
        $this->actingAs(new GenericUser(['id' => 9102]));
        for ($i = 0; $i < 5; $i++) $this->getJson('/api/request-limit-fixture')->assertOk();
        $limiter = RateLimiter::limiter('api');
        $this->assertInstanceOf(Unlimited::class, $limiter(Request::create('/api/request-limit-fixture')));
    }

    public function test_explicit_endpoint_limits_still_work(): void
    {
        $this->actingAs(new GenericUser(['id' => 9103]));
        $this->postJson('/api/sensitive-limit-fixture')->assertOk();
        $this->postJson('/api/sensitive-limit-fixture')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/request-limit-fixture')->assertOk();
    }

    public function test_login_node_and_webhook_limits_remain_separate_from_normal_pages(): void
    {
        $limiter = RateLimiter::limiter('api');
        $login = $limiter(Request::create('/api/login', 'POST'));
        $this->assertSame(60, $login->maxAttempts);
        $this->assertStringStartsWith('login:', $login->key);
        $node = $limiter(Request::create('/api/v2/server/config?machine_id=1&node_id=2'));
        $this->assertCount(2, $node);
        $this->assertSame(config('speedbox.node_rate_limit'), $node[0]->maxAttempts);
        $webhook = $limiter(Request::create('/api/telegram/webhook', 'POST'));
        $this->assertSame(config('speedbox.webhook_rate_limit'), $webhook->maxAttempts);
    }
}
