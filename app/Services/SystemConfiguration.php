<?php

namespace App\Services;

class SystemConfiguration
{
    /** Runtime settings for the integrated Panel and control plane. */
    public static function fields(string $section): array
    {
        $toggle = fn ($name, $label, $default) => compact('name', 'label', 'default') + ['type' => 'boolean', 'rules' => ['required', 'boolean']];
        $number = fn ($name, $label, $default, $min, $max) => compact('name', 'label', 'default', 'min', 'max') + ['type' => 'integer', 'rules' => ['required', 'integer', "between:{$min},{$max}"]];
        $text = fn ($name, $label, $default, $rules = []) => compact('name', 'label', 'default') + ['type' => 'text', 'rules' => array_merge(['nullable', 'string', 'max:2048'], $rules)];
        $event = fn ($name, $label, $default) => compact('name', 'label', 'default') + ['type' => 'select', 'rules' => ['required', 'integer', 'in:0,1'], 'options' => [0 => 'بدون عملیات', 1 => 'بازنشانی مصرف ترافیک']];
        return match ($section) {
            'general' => array_merge(self::fields('site'), self::fields('security')),
            'site' => [$toggle('force_https', 'اجبار استفاده از HTTPS', app()->environment('production', 'staging'))],
            'security' => [
                $toggle('password_limit_enable', 'محدودیت تلاش‌های ناموفق ورود', false),
                $number('password_limit_count', 'تعداد تلاش مجاز', 5, 1, 100),
                $number('password_limit_expire', 'مدت محدودیت (دقیقه)', 60, 1, 1440),
            ],
            'subscribe' => [
                $text('subscribe_url', 'آدرس سابسکرایب (چند آدرس با ویرگول)', config('app.url')),
                $text('subscribe_path', 'مسیر سابسکرایب', 's', ['required', 'max:100', 'regex:~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~']),
                $number('subscribe_update_interval', 'فاصله به‌روزرسانی کلاینت (ساعت)', 6, 1, 168),
                ['name' => 'reset_traffic_method', 'label' => 'روش بازنشانی دوره‌ای ترافیک', 'default' => 2, 'type' => 'select', 'rules' => ['required', 'integer', 'in:0,1,2,3,4'], 'options' => [0 => 'ابتدای هر ماه', 1 => 'ماهانه در روز پایان دوره', 2 => 'بدون بازنشانی', 3 => 'ابتدای هر سال', 4 => 'سالانه در روز پایان دوره']],
                $event('renew_order_event_id', 'عملیات پس از تمدید', 1),
                $toggle('show_info_to_server_enable', 'نمایش اطلاعات سابسکرایب در فهرست نودها', false),
                $toggle('show_online_to_server_enable', 'نمایش تعداد اتصالات آنلاین در فهرست نودها', false) + ['description' => 'براساس IPهای فعال؛ عدد با به‌روزرسانی اشتراک تازه می‌شود.'],
                $toggle('show_protocol_to_server_enable', 'نمایش نوع پروتکل کنار نام نودها', false),
                $toggle('userbot_remind_expire_enable', 'ارسال یادآوری انقضا در ربات کاربران', true) + ['description' => 'برای اکانت‌های متصل به ربات، در ۲۴ ساعت پایانی اعتبار.'],
                $toggle('userbot_remind_traffic_enable', 'ارسال هشدار مصرف ترافیک در ربات کاربران', true) + ['description' => 'برای اکانت‌های متصل به ربات، هنگام رسیدن مصرف به ۸۰ درصد؛ هر دوره فقط یک پیام.'],
            ],
            'server' => [
                $text('server_token', 'توکن عمومی سرورها', config('speedbox.server_token', ''), ['min:16']),
                $number('server_pull_interval', 'فاصله دریافت تنظیمات (ثانیه)', 60, 10, 600),
                $number('server_push_interval', 'فاصله ارسال گزارش (ثانیه)', 30, 5, 300),
                $toggle('server_ws_enable', 'فعال‌سازی WebSocket', false),
                $text('server_ws_url', 'آدرس WebSocket', '', ['regex:~^wss?://[^\s]+$~']),
            ],
            default => throw new \InvalidArgumentException('Unknown configuration section.'),
        };
    }

    public static function values(string $section): array
    {
        $values = [];
        foreach (self::fields($section) as $field) {
            $value = RuntimeSetting::get($field['name'], $field['default']);
            $values[$field['name']] = match ($field['type']) {
                'boolean' => (bool) $value,
                'integer', 'select' => (int) $value,
                default => (string) $value,
            };
        }
        return $values;
    }
}

