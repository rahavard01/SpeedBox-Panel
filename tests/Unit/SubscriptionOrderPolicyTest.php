<?php

namespace Tests\Unit;

use App\Services\SubscriptionOrderPolicy;
use App\Services\SubscriptionTrafficReset;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SubscriptionOrderPolicyTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('renewalCases')]
    public function test_renewal_uses_one_rule_for_every_selected_plan(bool $expired, bool $enabled, int $selectedPlan, bool $reset): void
    {
        // Any attempt to read obsolete new/change-order settings fails this expectation.
        Cache::shouldReceive('remember')->with('speedbox:setting:v2:renew_order_event_id', 60, \Mockery::type('Closure'))
            ->zeroOrMoreTimes()->andReturn(['value' => (int) $enabled]);
        $scheduler = \Mockery::mock(SubscriptionTrafficReset::class);
        $scheduler->shouldReceive('next')->once()->andReturn(null);
        $this->app->instance(SubscriptionTrafficReset::class, $scheduler);
        $oldExpiry = time() + ($expired ? -86400 : 86400);
        $newExpiry = max(time(), $oldExpiry) + 30 * 86400;
        $account = (object) ['plan_id' => 1, 'expired_at' => $oldExpiry, 'u' => 100,
            'd' => -20, 'reset_count' => 3, 'last_reset_at' => 123, 'traffic_warning_version' => 7];
        $update = (new SubscriptionOrderPolicy())->prepare($account, [
            'plan_id' => $selectedPlan, 'expired_at' => $newExpiry, 'u' => 0, 'd' => 0,
        ]);
        $this->assertSame($reset ? 0 : 100, $update['u']);
        $this->assertSame($reset ? 0 : -20, $update['d']);
        $this->assertSame($selectedPlan, $update['plan_id']);
        $this->assertSame($newExpiry, $update['expired_at']);
        $this->assertSame(8, $update['traffic_warning_version']);
        if ($reset) $this->assertSame(4, $update['reset_count']);
        else $this->assertArrayNotHasKey('reset_count', $update);
    }

    public static function renewalCases(): array
    {
        return [
            'active same plan, disabled' => [false, false, 1, false],
            'active different plan, disabled' => [false, false, 2, false],
            'active same plan, enabled' => [false, true, 1, true],
            'active different plan, enabled' => [false, true, 2, true],
            'expired same plan, disabled' => [true, false, 1, true],
            'expired different plan, disabled' => [true, false, 2, true],
            'expired different plan, enabled' => [true, true, 2, true],
        ];
    }
}
