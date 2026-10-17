<?php

namespace Tests\Unit;

use App\Support\JalaliDateFormatter;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class JalaliDateFormatterTest extends TestCase
{
    public function test_it_formats_year_end_and_keeps_english_digits(): void
    {
        $date = CarbonImmutable::create(
            2026,
            10,
            4,
            23,
            15,
            0,
            'Asia/Tehran'
        );

        $this->assertSame(
            '23:15 12-07-1405',
            JalaliDateFormatter::format(
                $date,
                'H:i d-m-Y'
            )
        );
    }

    public function test_it_preserves_year_first_layout(): void
    {
        $date = CarbonImmutable::create(
            2025,
            3,
            21,
            8,
            5,
            0,
            'Asia/Tehran'
        );

        $this->assertSame(
            '1404-01-01 08:05',
            JalaliDateFormatter::format(
                $date,
                'Y-m-d H:i'
            )
        );
    }

    public function test_it_prevents_telegram_from_auto_linking_the_date(): void
    {
        $date = CarbonImmutable::create(
            2026,
            10,
            4,
            23,
            15,
            0,
            'Asia/Tehran'
        );

        $this->assertSame(
            "۲۳:۱۵ ۱۲-\u{2060}۰۷-\u{2060}۱۴۰۵",
            JalaliDateFormatter::forTelegram(
                $date,
                'H:i d-m-Y'
            )
        );
    }

    public function test_it_uses_the_requested_fallback(): void
    {
        $this->assertSame(
            '-',
            JalaliDateFormatter::format(
                null,
                'H:i d-m-Y',
                '-'
            )
        );
    }
}
