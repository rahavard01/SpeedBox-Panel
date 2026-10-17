<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserBotPlan extends Model
{
    protected $fillable = [
        'setting_id',
        'plan_key',
        'group_name',
        'title',
        'price',
        'description',
        'is_enabled',
    ];

    protected $casts = [
        'price' => 'integer',
        'is_enabled' => 'boolean',
    ];

    public static function orderedForSetting(int $settingId)
    {
        $groups = \Illuminate\Support\Facades\DB::table('panel_tariff_groups')->get()->keyBy('id');
        $defaultTime = $groups->first(fn ($g) => $g->group_type === 'time' && $g->is_default);
        $defaultVolume = $groups->first(fn ($g) => $g->group_type === 'volume' && $g->is_default);
        $periods = ['test' => 0, '1m' => 1, '3m' => 2, '6m' => 3, '12m' => 4, 'gig' => 5, 'traffic_gig' => 5, 'volume_gig' => 0];

        return static::where('setting_id', $settingId)->where('is_enabled', true)->get()
            ->sortBy(function ($plan) use ($groups, $defaultTime, $defaultVolume, $periods) {
                $key = (string) $plan->plan_key;
                preg_match('/^(\d+)_(.+)$/', $key, $match);
                $period = $match[2] ?? $key;
                $group = isset($match[1]) ? $groups->get((int) $match[1])
                    : ($period === 'volume_gig' ? $defaultVolume : $defaultTime);
                return [
                    ($group->group_type ?? ($period === 'volume_gig' ? 'volume' : 'time')) === 'volume' ? 1 : 0,
                    (int) ($group->sort_order ?? PHP_INT_MAX),
                    (int) ($group->id ?? PHP_INT_MAX),
                    $periods[$period] ?? 99,
                    (int) $plan->id,
                ];
            })->values();
    }
}