<?php

namespace App\Services;

use App\Models\EmergencyConfig;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class EmergencyConfigurations
{
    public function position(): string
    {
        return RuntimeSetting::get('emergency_configs_position', 'bottom') === 'top' ? 'top' : 'bottom';
    }

    public function merge(array $servers, ?array $types = null, string|array|null $filter = ''): array
    {
        // Existing installations remain usable until their pending migration runs.
        if (!Schema::hasTable('speedbox_emergency_configs')) return $servers;
        $external = [];
        $keywords = is_array($filter) ? $filter : SubscriptionFilter::keywords($filter);
        $position = $this->position();
        foreach (EmergencyConfig::where('show', true)->orderBy('sort')->orderBy('id')->get() as $config) {
            if (!SubscriptionFilter::matches(['type' => $config->protocol, 'name' => $config->name], $types ?? [], $keywords)) continue;
            try {
                $server = app(EmergencyConfigParser::class)->parse($config->config_uri, $config->name);
            } catch (InvalidArgumentException $error) {
                // Old imported rows must not break subscriptions for every account.
                // Log the ID and validation message, never the credential-bearing URI.
                Log::warning('Invalid emergency configuration omitted from subscription.', [
                    'id' => $config->id, 'reason' => $error->getMessage(),
                ]);
                continue;
            }
            $server['id'] = 'emergency-'.$config->id;
            $server['tags'] = $config->tags ?? [];
            $server['_emergency_position'] = $position;
            $external[] = $server;
        }
        return $position === 'top' ? array_merge($external, $servers) : array_merge($servers, $external);
    }
}
