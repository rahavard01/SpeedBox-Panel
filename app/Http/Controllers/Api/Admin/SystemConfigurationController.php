<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\RuntimeSetting;
use App\Services\SubscriptionTemplates;
use App\Services\SystemConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemConfigurationController extends Controller
{
    public function show(string $section)
    {
        return response()->json(['data' => SystemConfiguration::values($section), 'fields' => array_map(function ($field) {
            unset($field['rules']);
            return $field;
        }, SystemConfiguration::fields($section))]);
    }

    public function update(Request $request, string $section)
    {
        $fields = SystemConfiguration::fields($section);
        $rules = array_column($fields, 'rules', 'name');
        $data = $request->validate($rules);
        foreach (array_filter(array_map('trim', explode(',', (string) ($data['subscribe_url'] ?? '')))) as $url) {
            $sample = \App\Utils\Helper::replaceByPattern($url);
            if (!filter_var($sample, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($sample, PHP_URL_SCHEME)), ['http', 'https'], true) || parse_url($sample, PHP_URL_QUERY) || parse_url($sample, PHP_URL_FRAGMENT) || parse_url($sample, PHP_URL_USER)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['subscribe_url' => 'آدرس‌های اشتراک باید معتبر و با http:// یا https:// شروع شوند.']);
            }
        }
        if (isset($data['subscribe_path']) && in_array(explode('/', $data['subscribe_path'])[0], ['api', 'admin', 'panel', 'staff', 'login', 'build', 'speedbox', 'storage'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['subscribe_path' => 'این مسیر برای پنل رزرو شده است.']);
        }
        $previousResetMethod = (int) RuntimeSetting::get('reset_traffic_method', 2);
        DB::transaction(function () use ($fields, $data, $section, $previousResetMethod) {
            foreach ($fields as $field) {
                if (!array_key_exists($field['name'], $data)) continue;
                $value = $data[$field['name']];
                $value = match ($field['type']) {
                    'boolean' => (bool) $value,
                    'integer', 'select' => (int) $value,
                    default => $value,
                };
                $group = $section === 'general' ? ($field['name'] === 'force_https' ? 'site' : 'safe') : ($section === 'security' ? 'safe' : $section);
                RuntimeSetting::set($field['name'], $value, $group);
            }
            if (isset($data['reset_traffic_method']) && (int) $data['reset_traffic_method'] !== $previousResetMethod) {
                DB::table('v2_user')->whereIn('plan_id', DB::table('v2_plan')->select('id')->whereNull('reset_traffic_method'))->update(['next_reset_at' => null]);
            }
        });
        return response()->json(['message' => 'تنظیمات ذخیره شد.', 'data' => SystemConfiguration::values($section)]);
    }

    public function templates()
    {
        return response()->json(['data' => collect(SubscriptionTemplates::FORMATS)->map(fn ($label, $name) => [
            'name' => $name, 'key' => 'subscribe_template_'.$name, 'label' => $label, 'content' => SubscriptionTemplates::get($name),
        ])->values()]);
    }

    public function saveTemplate(Request $request, string $name)
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:1048576']]);
        SubscriptionTemplates::save($name, $data['content']);
        return response()->json(['message' => 'قالب اشتراک ذخیره شد.']);
    }

    public function resetTemplate(string $name)
    {
        SubscriptionTemplates::reset($name);
        return response()->json(['message' => 'قالب پیش‌فرض بازیابی شد.', 'data' => ['content' => SubscriptionTemplates::get($name)]]);
    }
}
