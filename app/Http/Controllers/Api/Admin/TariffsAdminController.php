<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TariffsAdminController extends Controller
{
    private const PLAN_KEYS = ['gig', 'test', '1m', '3m', '6m', '12m'];
    private const VOLUME_PLAN_KEY = 'volume_gig';
    private const GROUP_TYPE_TIME = 'time';
    private const GROUP_TYPE_VOLUME = 'volume';
    public function index()
    {
        $v2 = DB::table('v2_plan')
            ->select(['id', 'name', 'transfer_enable', 'device_limit'])
            ->orderBy('id')
            ->get()
            ->map(function ($r) {
                return [
                    'id' => (int) $r->id,
                    'label' => trim(($r->name ?? '') . ' - ' . (string) ($r->transfer_enable ?? 0) . 'G'),
                    'device_limit' => $r->device_limit === null ? null : (int) $r->device_limit,
                ];
            })
            ->values();

        $this->ensureDefaultGroup(self::GROUP_TYPE_TIME);
        $this->ensureDefaultGroup(self::GROUP_TYPE_VOLUME);

        $timeGroups = DB::table('panel_tariff_groups')
            ->where('enable', 1)
            ->where('group_type', self::GROUP_TYPE_TIME)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $volumeGroups = DB::table('panel_tariff_groups')
            ->where('enable', 1)
            ->where('group_type', self::GROUP_TYPE_VOLUME)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $timePlans = DB::table('panel_plan')
            ->whereIn('plan_key', self::PLAN_KEYS)
            ->get()
            ->groupBy('tariff_group_id');

        $groupAccessMeta = $this->groupAccessMeta($timeGroups->concat($volumeGroups));    
        $resultGroups = $timeGroups->map(function ($group) use ($timePlans, $groupAccessMeta) {
            $rows = ($timePlans[$group->id] ?? collect())->keyBy('plan_key');

            $items = [];
            foreach (self::PLAN_KEYS as $key) {
                $r = $rows[$key] ?? null;

                $items[] = [
                    'id' => $r ? (int) $r->id : null,
                    'plan_key' => $key,
                    'key' => $key,
                    'default_price' => $r ? (string) ($r->default_price ?? '') : '',
                    'details' => $r ? (string) ($r->details ?? '') : '',
                    'enable' => $r ? (int) ($r->enable ?? 0) : 0,
                    'v2_plan_id' => $r ? ($r->v2_plan_id ?? null) : null,
                    'device_limit' => $r?->device_limit === null ? null : (int) $r->device_limit,
                ];
            }

            return [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'is_default' => (int) $group->is_default,
                'group_type' => (string) $group->group_type,
                'enable' => (int) $group->enable,
                'sort_order' => (int) $group->sort_order,
                'partner_access_mode' => $groupAccessMeta[$group->id]['mode'] ?? 'all_partners',
                'allowed_partner_ids' => $groupAccessMeta[$group->id]['ids'] ?? [],
                'allowed_partners_count' => $groupAccessMeta[$group->id]['count'] ?? 0,
                'eligible_partners_count' => $groupAccessMeta[$group->id]['eligible_count'] ?? 0,
                'items' => $items,
            ];
        })->values();

        $volumePlans = DB::table('panel_plan')
            ->where('plan_key', self::VOLUME_PLAN_KEY)
            ->get()
            ->keyBy('tariff_group_id');

        $resultVolumeGroups = $volumeGroups->map(function ($group) use ($volumePlans, $groupAccessMeta) {
            $r = $volumePlans[$group->id] ?? null;

            $item = [
                'id' => $r ? (int) $r->id : null,
                'plan_key' => self::VOLUME_PLAN_KEY,
                'key' => self::VOLUME_PLAN_KEY,
                'default_price' => $r ? (string) ($r->default_price ?? '') : '',
                'details' => $r ? (string) ($r->details ?? '') : '',
                'enable' => $r ? (int) ($r->enable ?? 1) : 1,
                'v2_plan_id' => $r ? ($r->v2_plan_id ?? null) : null,
                'device_limit' => $r?->device_limit === null ? null : (int) $r->device_limit,
            ];

            return [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'is_default' => (int) $group->is_default,
                'group_type' => (string) $group->group_type,
                'enable' => (int) $group->enable,
                'sort_order' => (int) $group->sort_order,
                'min_traffic_gb' => (int) ($group->min_traffic_gb ?? 10),
                'partner_access_mode' => $groupAccessMeta[$group->id]['mode'] ?? 'all_partners',
                'allowed_partner_ids' => $groupAccessMeta[$group->id]['ids'] ?? [],
                'allowed_partners_count' => $groupAccessMeta[$group->id]['count'] ?? 0,
                'eligible_partners_count' => $groupAccessMeta[$group->id]['eligible_count'] ?? 0,
                'items' => [$item],
                'volume_item' => $item,
            ];
        })->values();

        $defaultGroup = $resultGroups->firstWhere('is_default', 1) ?: $resultGroups->first();
        $defaultVolumeGroup = $resultVolumeGroups->firstWhere('is_default', 1) ?: $resultVolumeGroups->first();

        $volumeItem = $defaultVolumeGroup['volume_item'] ?? [
            'id' => null,
            'plan_key' => self::VOLUME_PLAN_KEY,
            'key' => self::VOLUME_PLAN_KEY,
            'default_price' => '',
            'details' => '',
            'enable' => 1,
            'v2_plan_id' => null,
            'device_limit' => null,
        ];

        return response()->json([
            'status' => 'ok',
            'v2_options' => $v2,

            // برای سازگاری با ظاهر فعلی
            'volume_item' => $volumeItem,

            // خروجی زمانی‌ها
            'groups' => $resultGroups,

            // خروجی جدید حجمی‌ها
            'volume_groups' => $resultVolumeGroups,

            // برای سازگاری با ظاهر فعلی پنل
            'items' => $defaultGroup['items'] ?? [],
        ]);
    }

    public function update(Request $req)
    {
        $req->validate([
            'volume_groups.*.min_traffic_gb' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);

        $groupsInput = $req->input('groups');
        $volumeGroupsInput = $req->input('volume_groups');

        if (
            (is_array($groupsInput) && !empty($groupsInput)) ||
            (is_array($volumeGroupsInput) && !empty($volumeGroupsInput))
        ) {
            return $this->updateGroups(
                is_array($groupsInput) ? $groupsInput : [],
                is_array($volumeGroupsInput) ? $volumeGroupsInput : []
            );
        }

        $items = $req->input('items', []);
        if (!is_array($items) || empty($items)) {
            return response()->json([
                'status' => 'error',
                'message' => 'ورودی معتبر نیست',
            ], 422);
        }

        $defaultGroupId = $this->ensureDefaultGroup(self::GROUP_TYPE_TIME);

        return $this->saveGroupPlans($defaultGroupId, 'پلن پیشفرض', true, 1, $items);
    }

    public function patchEnable($planKey, Request $req)
    {
        $allowedPlanKeys = array_merge(self::PLAN_KEYS, [self::VOLUME_PLAN_KEY]);

        if (!in_array($planKey, $allowedPlanKeys, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'کلید نامعتبر است',
            ], 422);
        }

        $enable = $req->input('enable', null);
        if (!in_array((int) $enable, [0, 1], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'مقدار وضعیت نامعتبر است',
            ], 422);
        }

        $groupId = (int) ($req->input('tariff_group_id') ?: $req->input('group_id') ?: 0);
        if ($groupId <= 0) {
            $groupId = $this->ensureDefaultGroup(
                $planKey === self::VOLUME_PLAN_KEY ? self::GROUP_TYPE_VOLUME : self::GROUP_TYPE_TIME
            );
        }

        $row = DB::table('panel_plan')
            ->where('tariff_group_id', $groupId)
            ->where('plan_key', $planKey)
            ->first();

        $msg = ((int) $enable === 1) ? 'پلن با موفقیت فعال شد' : 'پلن با موفقیت غیرفعال شد';

        if ($row) {
            if ((int) $row->enable === (int) $enable) {
                return response()->json([
                    'status' => 'ok',
                    'changed' => false,
                    'message' => 'تغییری اعمال نشد',
                ]);
            }

            DB::table('panel_plan')->where('id', $row->id)->update([
                'enable' => (int) $enable,
            ]);

            if ((int) $enable === 0 && DB::getSchemaBuilder()->hasTable('user_bot_plans')) {
                DB::table('user_bot_plans')
                    ->where('plan_key', $groupId . '_' . $planKey)
                    ->update(['is_enabled' => 0]);
            }

            return response()->json([
                'status' => 'ok',
                'changed' => true,
                'message' => $msg,
            ]);
        }

        DB::table('panel_plan')->insert([
            'tariff_group_id' => $groupId,
            'plan_key' => $planKey,
            'enable' => (int) $enable,
            'default_price' => '',
            'details' => '',
            'v2_plan_id' => 0,
        ]);

        return response()->json([
            'status' => 'ok',
            'changed' => true,
            'message' => $msg,
        ]);
    }

    public function percentAdjust(Request $req)
    {
        $mode = $req->input('mode');
        $percent = (float) ($req->input('percent', 0));

        if (!in_array($mode, ['increase', 'decrease'], true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'نوع تغییر نامعتبر است',
            ], 422);
        }

        if ($percent <= 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'درصد تغییر را وارد کنید',
            ], 422);
        }

        $factor = $mode === 'increase'
            ? (1 + $percent / 100.0)
            : (1 - $percent / 100.0);

        $affPlan = 0;
        $affUserTariffPrices = 0;

        DB::transaction(function () use ($factor, &$affPlan, &$affUserTariffPrices) {
            $affPlan += DB::update(
                "UPDATE panel_plan
                 SET default_price = CAST(
                    TRUNCATE(CAST(default_price AS DECIMAL(20,6)) * CAST(? AS DECIMAL(10,6)), 0) AS CHAR
                 )
                 WHERE plan_key IN ('gig','test','1m','3m','6m','12m','volume_gig')
                   AND default_price IS NOT NULL
                   AND TRIM(default_price) <> ''",
                [$factor]
            );

            if (DB::getSchemaBuilder()->hasTable('panel_user_tariff_prices')) {
                $affUserTariffPrices += DB::update(
                    "UPDATE panel_user_tariff_prices
                     SET price = CAST(
                        TRUNCATE(CAST(price AS DECIMAL(20,6)) * CAST(? AS DECIMAL(10,6)), 0) AS CHAR
                     )
                     WHERE price IS NOT NULL
                       AND TRIM(price) <> ''",
                    [$factor]
                );
            }
        });

        $changed = ($affPlan > 0) || ($affUserTariffPrices > 0);

        return response()->json([
            'status' => 'ok',
            'factor' => $factor,
            'changed' => $changed,
            'affected' => [
                'panel_plan' => $affPlan,
                'panel_user_tariff_prices' => $affUserTariffPrices,
            ],
            'message' => $changed ? 'تغییرات با موفقیت ذخیره شد' : 'تغییری اعمال نشد',
        ]);
    }

    public function destroyGroup($id)
    {
        $id = (int) $id;

        $group = DB::table('panel_tariff_groups')->where('id', $id)->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'طرح پیدا نشد',
            ], 404);
        }

        if ((int) $group->is_default === 1) {
            return response()->json([
                'status' => 'error',
                'message' => 'طرح پیشفرض قابل حذف نیست',
            ], 422);
        }

        DB::transaction(function () use ($id) {
            if (DB::getSchemaBuilder()->hasTable('panel_user_tariff_prices')) {
                DB::table('panel_user_tariff_prices')
                    ->where('tariff_group_id', $id)
                    ->delete();
            }

            if (DB::getSchemaBuilder()->hasTable('user_bot_plans')) {
                DB::table('user_bot_plans')
                    ->where('plan_key', 'like', $id . '\_%')
                    ->delete();
            }

            DB::table('panel_plan')
                ->where('tariff_group_id', $id)
                ->delete();

            DB::table('panel_tariff_groups')
                ->where('id', $id)
                ->delete();
        });

        return response()->json([
            'status' => 'ok',
            'message' => 'طرح با موفقیت حذف شد',
        ]);
    }

    public function partnersForGroup($id)
    {
        $group = DB::table('panel_tariff_groups')->where('id', (int) $id)->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'طرح پیدا نشد',
            ], 404);
        }

        $partners = $this->eligiblePartnersForGroup((string) ($group->group_type ?? self::GROUP_TYPE_TIME));

        $selectedIds = DB::table('panel_tariff_group_partner_access')
            ->where('tariff_group_id', (int) $group->id)
            ->pluck('partner_id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        return response()->json([
            'status' => 'ok',
            'group' => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'group_type' => (string) ($group->group_type ?? self::GROUP_TYPE_TIME),
                'partner_access_mode' => (string) ($group->partner_access_mode ?? 'all_partners'),
            ],
            'selected_partner_ids' => $selectedIds,
            'partners' => $partners,
        ]);
    }

    public function updatePartnersForGroup($id, Request $req)
    {
        $group = DB::table('panel_tariff_groups')->where('id', (int) $id)->first();

        if (!$group) {
            return response()->json([
                'status' => 'error',
                'message' => 'طرح پیدا نشد',
            ], 404);
        }

        $partnerIds = $req->input('partner_ids', []);

        if (!is_array($partnerIds)) {
            return response()->json([
                'status' => 'error',
                'message' => 'لیست نمایندگان معتبر نیست',
            ], 422);
        }

        $eligibleIds = collect($this->eligiblePartnersForGroup((string) ($group->group_type ?? self::GROUP_TYPE_TIME)))
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $partnerIds = collect($partnerIds)
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->filter(fn ($v) => in_array($v, $eligibleIds, true))
            ->values()
            ->all();

        $accessMode = count($eligibleIds) > 0 && count($partnerIds) >= count($eligibleIds)
            ? 'all_partners'
            : 'selected_partners';

        DB::transaction(function () use ($group, $partnerIds, $accessMode) {
            DB::table('panel_tariff_group_partner_access')
                ->where('tariff_group_id', (int) $group->id)
                ->delete();

            if ($accessMode === 'selected_partners') {
                foreach ($partnerIds as $partnerId) {
                    DB::table('panel_tariff_group_partner_access')->insert([
                        'tariff_group_id' => (int) $group->id,
                        'partner_id' => (int) $partnerId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            DB::table('panel_tariff_groups')
                ->where('id', (int) $group->id)
                ->update([
                    'partner_access_mode' => $accessMode,
                    'updated_at' => now(),
                ]);
        });

        return response()->json([
            'status' => 'ok',
            'message' => 'تغییرات دسترسی نمایندگان با موفقیت ذخیره شد',
            'partner_access_mode' => $accessMode,
            'selected_partner_ids' => $accessMode === 'all_partners' ? [] : $partnerIds,
            'allowed_partners_count' => $accessMode === 'all_partners' ? count($eligibleIds) : count($partnerIds),
            'eligible_partners_count' => count($eligibleIds),
        ]);
    }

    private function eligiblePartnersForGroup(string $groupType): array
    {
        $allowedTypes = $groupType === self::GROUP_TYPE_VOLUME
            ? ['volume', 'both']
            : ['time', 'both'];

        return DB::table('panel_users')
            ->select(['id', 'name', 'email', 'code', 'partner_plan_type'])
            ->where('role', 2)
            ->where('banned', 0)
            ->whereIn('partner_plan_type', $allowedTypes)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(function ($partner) {
                return [
                    'id' => (int) $partner->id,
                    'name' => (string) $partner->name,
                    'email' => (string) $partner->email,
                    'code' => (string) ($partner->code ?? ''),
                    'partner_plan_type' => (string) ($partner->partner_plan_type ?? 'time'),
                ];
            })
            ->values()
            ->all();
    }

    private function groupAccessMeta($groups): array
    {
        $groups = collect($groups)->values();

        $groupIds = $groups
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->values()
            ->all();

        if (empty($groupIds)) {
            return [];
        }

        $accessRows = DB::table('panel_tariff_group_partner_access')
            ->whereIn('tariff_group_id', $groupIds)
            ->get()
            ->groupBy('tariff_group_id');

        $meta = [];

        foreach ($groups as $group) {
            $groupId = (int) $group->id;
            $mode = (string) ($group->partner_access_mode ?? 'all_partners');

            $ids = ($accessRows[$groupId] ?? collect())
                ->pluck('partner_id')
                ->map(fn ($v) => (int) $v)
                ->values()
                ->all();

            $eligibleCount = count($this->eligiblePartnersForGroup((string) ($group->group_type ?? self::GROUP_TYPE_TIME)));

            $meta[$groupId] = [
                'mode' => $mode,
                'ids' => $ids,
                'count' => count($ids),
                'eligible_count' => $eligibleCount,
            ];
        }

        return $meta;
    }

    private function updateGroups(array $groupsInput, array $volumeGroupsInput = [])
    {
        $changed = 0;

        DB::transaction(function () use ($groupsInput, $volumeGroupsInput, &$changed) {
            $oldGigPrices = DB::table('panel_plan')
                ->where('plan_key', 'gig')
                ->pluck('default_price', 'tariff_group_id')
                ->map(fn ($v) => (string) $v)
                ->all();

            $oldVolumePrices = DB::table('panel_plan')
                ->where('plan_key', self::VOLUME_PLAN_KEY)
                ->pluck('default_price', 'tariff_group_id')
                ->map(fn ($v) => (string) $v)
                ->all();

            foreach (array_values($groupsInput) as $index => $group) {
                $groupId = (int) ($group['id'] ?? 0);
                $name = trim((string) ($group['name'] ?? ''));

                if ($name === '') {
                    throw new \RuntimeException('نام همه طرح‌های زمانی را وارد کنید');
                }

                if ($groupId > 0) {
                    DB::table('panel_tariff_groups')->where('id', $groupId)->update([
                        'name' => $name,
                        'group_type' => self::GROUP_TYPE_TIME,
                        'enable' => (int) ($group['enable'] ?? 1),
                        'sort_order' => (int) ($group['sort_order'] ?? ($index + 1)),
                        'updated_at' => now(),
                    ]);
                } else {
                    $groupId = DB::table('panel_tariff_groups')->insertGetId([
                        'name' => $name,
                        'is_default' => 0,
                        'group_type' => self::GROUP_TYPE_TIME,
                        'partner_access_mode' => 'selected_partners',
                        'enable' => (int) ($group['enable'] ?? 1),
                        'sort_order' => (int) ($group['sort_order'] ?? ($index + 1)),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->validateItems($group['items'] ?? []);

                foreach (self::PLAN_KEYS as $key) {
                    $item = $this->itemByKey($group['items'], $key);

                    $payload = [
                        'tariff_group_id' => $groupId,
                        'plan_key' => $key,
                        'name' => $this->planName($key),
                        'default_price' => (string) $item['default_price'],
                        'details' => (string) ($item['details'] ?? ''),
                        'enable' => (int) ($item['enable'] ?? 0),
                        'v2_plan_id' => (int) $item['v2_plan_id'],
                        'updated_at' => now(),
                    ];

                    DB::table('panel_plan')->updateOrInsert(
                        [
                            'tariff_group_id' => $groupId,
                            'plan_key' => $key,
                        ],
                        $payload
                    );
                    app(\App\Services\TariffConnectionPolicy::class)->saved($groupId, $key, $item);

                    $changed++;
                }

                $newGigItem = $this->itemByKey($group['items'], 'gig');
                $newGigPrice = (string) ($newGigItem['default_price'] ?? '');

                $oldGigPrice = $oldGigPrices[$groupId] ?? null;

                if ($newGigPrice !== '' && $newGigPrice !== $oldGigPrice) {
                    DB::table('panel_users')
                        ->whereIn('partner_plan_type', ['time', 'both'])
                        ->where('enable_personalized_price', 0)
                        ->update([
                            'traffic_price' => (int) $newGigPrice,
                            'updated_at' => now(),
                        ]);
                }
            }

            foreach (array_values($volumeGroupsInput) as $index => $group) {
                $groupId = (int) ($group['id'] ?? 0);
                $name = trim((string) ($group['name'] ?? ''));

                if ($name === '') {
                    throw new \RuntimeException('نام همه طرح‌های حجمی را وارد کنید');
                }

                $item = $this->volumeItemFromGroup($group);
                $this->validateVolumeItem($item);

                $minimumTraffic = (int) ($group['min_traffic_gb'] ?? ($groupId > 0
                    ? DB::table('panel_tariff_groups')->where('id', $groupId)->value('min_traffic_gb') ?? 10
                    : 10));

                if ($groupId > 0) {
                    DB::table('panel_tariff_groups')->where('id', $groupId)->update([
                        'name' => $name,
                        'group_type' => self::GROUP_TYPE_VOLUME,
                        'enable' => (int) ($group['enable'] ?? 1),
                        'sort_order' => (int) ($group['sort_order'] ?? ($index + 1)),
                        'min_traffic_gb' => $minimumTraffic,
                        'updated_at' => now(),
                    ]);
                } else {
                    $groupId = DB::table('panel_tariff_groups')->insertGetId([
                        'name' => $name,
                        'is_default' => 0,
                        'group_type' => self::GROUP_TYPE_VOLUME,
                        'partner_access_mode' => 'selected_partners',
                        'enable' => (int) ($group['enable'] ?? 1),
                        'sort_order' => (int) ($group['sort_order'] ?? ($index + 1)),
                        'min_traffic_gb' => $minimumTraffic,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('panel_plan')->updateOrInsert(
                    [
                        'tariff_group_id' => $groupId,
                        'plan_key' => self::VOLUME_PLAN_KEY,
                    ],
                    [
                        'tariff_group_id' => $groupId,
                        'plan_key' => self::VOLUME_PLAN_KEY,
                        'name' => 'هر گیگ ترافیک - یک‌ماهه',
                        'default_price' => (string) $item['default_price'],
                        'details' => (string) ($item['details'] ?? ''),
                        'enable' => 1,
                        'v2_plan_id' => (int) $item['v2_plan_id'],
                        'updated_at' => now(),
                    ]
                );
                app(\App\Services\TariffConnectionPolicy::class)->saved($groupId, self::VOLUME_PLAN_KEY, $item);

                $oldVolumePrice = $oldVolumePrices[$groupId] ?? null;
                $newVolumePrice = (string) ($item['default_price'] ?? '');

                $isDefaultVolumeGroup = (int) DB::table('panel_tariff_groups')
                    ->where('id', $groupId)
                    ->value('is_default') === 1;

                if ($isDefaultVolumeGroup && $newVolumePrice !== '' && $newVolumePrice !== $oldVolumePrice) {
                    DB::table('panel_users')
                        ->whereIn('partner_plan_type', ['volume', 'both'])
                        ->where('enable_personalized_price', 0)
                        ->update([
                            'traffic_price' => (int) $newVolumePrice,
                            'updated_at' => now(),
                        ]);
                }

                $changed++;
            }

            $volumeItem = request()->input('volume_item');

            if (is_array($volumeItem) && empty($volumeGroupsInput)) {
                $defaultVolumeGroupId = $this->ensureDefaultGroup(self::GROUP_TYPE_VOLUME);

                $this->validateVolumeItem($volumeItem);

                DB::table('panel_plan')->updateOrInsert(
                    [
                        'tariff_group_id' => $defaultVolumeGroupId,
                        'plan_key' => self::VOLUME_PLAN_KEY,
                    ],
                    [
                        'tariff_group_id' => $defaultVolumeGroupId,
                        'plan_key' => self::VOLUME_PLAN_KEY,
                        'name' => 'هر گیگ ترافیک - یک‌ماهه',
                        'default_price' => (string) $volumeItem['default_price'],
                        'details' => (string) ($volumeItem['details'] ?? ''),
                        'enable' => 1,
                        'v2_plan_id' => (int) $volumeItem['v2_plan_id'],
                        'updated_at' => now(),
                    ]
                );
                app(\App\Services\TariffConnectionPolicy::class)->saved($defaultVolumeGroupId, self::VOLUME_PLAN_KEY, $volumeItem);

                $oldVolumePrice = $oldVolumePrices[$defaultVolumeGroupId] ?? null;
                $newVolumePrice = (string) ($volumeItem['default_price'] ?? '');

                if ($newVolumePrice !== '' && $newVolumePrice !== $oldVolumePrice) {
                    DB::table('panel_users')
                        ->whereIn('partner_plan_type', ['volume', 'both'])
                        ->where('enable_personalized_price', 0)
                        ->update([
                            'traffic_price' => (int) $newVolumePrice,
                            'updated_at' => now(),
                        ]);
                }

                $changed++;
            }
        });

        return response()->json([
            'status' => 'ok',
            'changed' => $changed > 0,
            'message' => $changed > 0 ? 'تغییرات با موفقیت ذخیره شد' : 'تغییری اعمال نشد',
        ]);
    }

    private function saveGroupPlans(int $groupId, string $groupName, bool $isDefault, int $sortOrder, array $items)
    {
        $this->validateItems($items);

        $changed = 0;

        DB::transaction(function () use ($groupId, $groupName, $isDefault, $sortOrder, $items, &$changed) {
            DB::table('panel_tariff_groups')->where('id', $groupId)->update([
                'name' => $groupName,
                'is_default' => $isDefault ? 1 : 0,
                'group_type' => self::GROUP_TYPE_TIME,
                'enable' => 1,
                'sort_order' => $sortOrder,
                'updated_at' => now(),
            ]);

            foreach (self::PLAN_KEYS as $key) {
                $item = $this->itemByKey($items, $key);
                app(\App\Services\TariffConnectionPolicy::class)->validate($item);

                $payload = [
                    'tariff_group_id' => $groupId,
                    'plan_key' => $key,
                    'name' => $this->planName($key),
                    'default_price' => (string) $item['default_price'],
                    'details' => (string) ($item['details'] ?? ''),
                    'enable' => (int) ($item['enable'] ?? 0),
                    'v2_plan_id' => (int) $item['v2_plan_id'],
                ];

                DB::table('panel_plan')->updateOrInsert(
                    [
                        'tariff_group_id' => $groupId,
                        'plan_key' => $key,
                    ],
                    $payload
                );
                app(\App\Services\TariffConnectionPolicy::class)->saved($groupId, $key, $item);

                $changed++;
            }
        });

        return response()->json([
            'status' => 'ok',
            'changed' => $changed > 0,
            'message' => $changed > 0 ? 'تغییرات با موفقیت ذخیره شد' : 'تغییری اعمال نشد',
        ]);
    }

    private function validateItems(array $items): void
    {
        if (empty($items)) {
            throw new \RuntimeException('تعرفه‌های پلن ارسال نشده است');
        }

        foreach (self::PLAN_KEYS as $key) {
            $item = $this->itemByKey($items, $key);
            app(\App\Services\TariffConnectionPolicy::class)->validate($item);

            $price = trim((string) ($item['default_price'] ?? ''));
            if ($price === '') {
                throw new \RuntimeException('قیمت کلیه پلن‌ها را وارد کنید');
            }

            if (!preg_match('/^\d+(\.\d+)?$/', $price)) {
                throw new \RuntimeException('قیمت‌ها باید عددی باشند');
            }

            if ($key !== 'gig') {
                $details = trim((string) ($item['details'] ?? ''));
                if ($details === '') {
                    throw new \RuntimeException('توضیحات کلیه پلن‌ها را وارد کنید');
                }

                $v2PlanId = $item['v2_plan_id'] ?? null;
                if (!$v2PlanId) {
                    throw new \RuntimeException('پلن متصل به پنل را برای کلیه پلن‌ها انتخاب کنید');
                }
            }

        }
    }

    private function itemByKey(array $items, string $key): array
    {
        foreach ($items as $item) {
            $itemKey = $item['plan_key'] ?? $item['key'] ?? null;

            if ($itemKey === $key) {
                return $item;
            }
        }

        throw new \RuntimeException('تعرفه ' . $key . ' ارسال نشده است');
    }

    private function volumeItemFromGroup(array $group): array
    {
        $items = $group['items'] ?? [];

        if (is_array($items) && !empty($items)) {
            foreach ($items as $item) {
                $itemKey = $item['plan_key'] ?? $item['key'] ?? null;

                if ($itemKey === self::VOLUME_PLAN_KEY) {
                    return $item;
                }
            }

            return $items[0];
        }

        return $group;
    }

    private function validateVolumeItem(array $item): void
    {
        app(\App\Services\TariffConnectionPolicy::class)->validate($item);
        $price = trim((string) ($item['default_price'] ?? ''));

        if ($price === '') {
            throw new \RuntimeException('قیمت طرح حجمی را وارد کنید');
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $price)) {
            throw new \RuntimeException('قیمت طرح حجمی باید عددی باشد');
        }

        $details = trim((string) ($item['details'] ?? ''));
        if ($details === '') {
            throw new \RuntimeException('توضیحات طرح حجمی را وارد کنید');
        }

        $v2PlanId = $item['v2_plan_id'] ?? null;
        if (!$v2PlanId) {
            throw new \RuntimeException('پلن متصل به پنل را برای طرح حجمی انتخاب کنید');
        }
    }

    private function ensureDefaultGroup(string $groupType = self::GROUP_TYPE_TIME): int
    {
        $id = DB::table('panel_tariff_groups')
            ->where('group_type', $groupType)
            ->where('is_default', 1)
            ->value('id');

        if ($id) {
            return (int) $id;
        }

        $name = $groupType === self::GROUP_TYPE_VOLUME ? 'حجمی' : 'پلن پیشفرض';

        return (int) DB::table('panel_tariff_groups')->insertGetId([
            'name' => $name,
            'is_default' => 1,
            'group_type' => $groupType,
            'enable' => 1,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function planName(string $key): string
    {
        return match ($key) {
            'gig' => 'هر گیگ ترافیک',
            'test' => 'اکانت تست',
            '1m' => 'اکانت یک ماهه',
            '3m' => 'اکانت سه ماهه',
            '6m' => 'اکانت شش ماهه',
            '12m' => 'اکانت یک ساله',
            default => $key,
        };
    }

}
