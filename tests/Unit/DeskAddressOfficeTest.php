<?php

namespace Tests\Unit;

use App\Support\DeskAddressOffice;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests (no Laravel app bootstrap). Pass $now explicitly so now() is unused.
 */
class DeskAddressOfficeTest extends TestCase
{
    public function test_can_reveal_with_moscow_timezone_when_app_is_utc(): void
    {
        date_default_timezone_set('UTC');

        // Wall-clock call 17:00 Moscow stored as naive datetime (app tz UTC → Carbon sees +00:00).
        $callAt = Carbon::parse('2026-09-12 17:00:00', 'UTC');
        // 14:04 UTC == 17:04 Moscow — after reveal window 16:30 MSK
        $nowUtc = Carbon::parse('2026-09-12 14:04:00', 'UTC');
        $nowMoscow = Carbon::parse('2026-09-12 17:04:00', 'Europe/Moscow');

        $this->assertTrue(DeskAddressOffice::canReveal($callAt, $nowUtc, 'Europe/Moscow'));
        $this->assertTrue(DeskAddressOffice::canReveal($callAt, $nowMoscow, 'Europe/Moscow'));
        // null TZ → DEFAULT Europe/Moscow (KP sync often omits timezone)
        $this->assertTrue(DeskAddressOffice::canReveal($callAt, $nowUtc, null));

        $revealAt = DeskAddressOffice::revealAt($callAt, null);
        $this->assertNotNull($revealAt);
        $this->assertSame('2026-09-12 16:30:00', $revealAt->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Moscow', $revealAt->timezoneName);
    }

    public function test_null_timezone_defaults_to_moscow_wall_clock(): void
    {
        date_default_timezone_set('UTC');
        // Order 2642017-like: call 13:31 wall, reveal 13:01; now ~13:57 MSK (=10:57 UTC)
        $callAt = Carbon::parse('2026-09-13 13:31:00', 'UTC');
        $nowUtc = Carbon::parse('2026-09-13 10:57:00', 'UTC');
        $nowMoscow = Carbon::parse('2026-09-13 13:57:00', 'Europe/Moscow');

        $this->assertSame('Europe/Moscow', DeskAddressOffice::resolveTimezone(null));
        $this->assertSame('Europe/Moscow', DeskAddressOffice::resolveTimezone(''));
        $this->assertSame('Asia/Yekaterinburg', DeskAddressOffice::resolveTimezone('Asia/Yekaterinburg'));

        $this->assertTrue(DeskAddressOffice::canReveal($callAt, $nowUtc, null));
        $this->assertTrue(DeskAddressOffice::canReveal($callAt, $nowMoscow, null));

        $revealAt = DeskAddressOffice::revealAt($callAt, null);
        $this->assertNotNull($revealAt);
        $this->assertSame('2026-09-13 13:01:00', $revealAt->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Moscow', $revealAt->timezoneName);
    }

    public function test_still_locked_before_reveal_window_in_city_tz(): void
    {
        date_default_timezone_set('UTC');
        $callAt = Carbon::parse('2026-09-12 17:00:00', 'UTC');
        // 16:29 MSK = 13:29 UTC — one minute before reveal
        $nowMoscow = Carbon::parse('2026-09-12 16:29:00', 'Europe/Moscow');
        $nowUtc = Carbon::parse('2026-09-12 13:29:00', 'UTC');

        $this->assertFalse(DeskAddressOffice::canReveal($callAt, $nowMoscow, 'Europe/Moscow'));
        $this->assertFalse(DeskAddressOffice::canReveal($callAt, $nowUtc, null));
    }

    public function test_can_reveal_for_master_by_visit_status_before_window(): void
    {
        date_default_timezone_set('UTC');
        $callAt = Carbon::parse('2026-09-12 17:00:00', 'UTC');
        $nowMoscow = Carbon::parse('2026-09-12 16:29:00', 'Europe/Moscow');

        $this->assertFalse(DeskAddressOffice::canRevealForMaster($callAt, 'pending', 'Europe/Moscow', $nowMoscow));
        $this->assertTrue(DeskAddressOffice::canRevealForMaster($callAt, 'on_way', 'Europe/Moscow', $nowMoscow));
        $this->assertTrue(DeskAddressOffice::canRevealForMaster($callAt, 'in_progress', 'Europe/Moscow', $nowMoscow));
        $this->assertTrue(DeskAddressOffice::canRevealForMaster($callAt, 'in_progress_sd', 'Europe/Moscow', $nowMoscow));
    }

    public function test_strips_flat_after_house_with_corpus_like_69k3(): void
    {
        $full = 'Рязань Михайловское шоссе, 69к3, 33, подъезд 3, этаж 1';
        $street = DeskAddressOffice::streetAddressForDisplay($full);
        $this->assertSame('Рязань Михайловское шоссе, 69к3', $street);
        $this->assertStringNotContainsString('33', $street);
        $this->assertStringNotContainsString('подъезд', $street);
        $this->assertStringNotContainsString('этаж', $street);
        $this->assertTrue(DeskAddressOffice::addressLooksLikeHasOffice($full));
    }

    public function test_address_for_display_hides_office_text_before_window(): void
    {
        date_default_timezone_set('UTC');
        // call 18:00 wall → reveal 17:30; now 12:00 UTC = 15:00 MSK — ещё рано
        $callAt = Carbon::parse('2026-09-17 18:00:00', 'UTC');
        $now = Carbon::parse('2026-09-17 12:00:00', 'UTC');
        $this->assertFalse(DeskAddressOffice::canReveal($callAt, $now, 'Europe/Moscow'));

        $full = 'Михайловское шоссе, 69к3, 33, подъезд 3, этаж 1';
        $this->assertSame('Михайловское шоссе, 69к3', DeskAddressOffice::streetAddressForDisplay($full));
    }

    public function test_strips_simple_and_slash_houses(): void
    {
        $this->assertSame(
            'ул. Ленина, 28',
            DeskAddressOffice::streetAddressForDisplay('ул. Ленина, 28, 12, подъезд 1')
        );
        $this->assertSame(
            'ул. Ленина, 28/9',
            DeskAddressOffice::streetAddressForDisplay('ул. Ленина, 28/9, 4')
        );
        $this->assertSame(
            'ул. Мира, 10А',
            DeskAddressOffice::streetAddressForDisplay('ул. Мира, 10А, 7, этаж 2')
        );
    }
}
