<?php

namespace Tests\Unit;

use App\Support\DeskAccess;
use App\Support\DeskClientPhonePrivacy;
use PHPUnit\Framework\TestCase;

class DeskClientPhonePrivacyTest extends TestCase
{
    public function test_hidden_for_branch_and_region_roles(): void
    {
        foreach (['senior_manager', 'manager', 'order_manager', 'branch_head', 'regional_director'] as $role) {
            $this->assertFalse(
                DeskAccess::canSeeClientPhone(['roles' => [$role]]),
                $role.' must not see client phone'
            );
        }
    }

    public function test_visible_for_cc_and_general(): void
    {
        foreach (['call_center', 'senior_dispatcher', 'general_director'] as $role) {
            $this->assertTrue(DeskAccess::canSeeClientPhone(['roles' => [$role]]));
        }
    }

    public function test_developer_bypasses_hidden_role(): void
    {
        $this->assertTrue(DeskAccess::canSeeClientPhone([
            'roles' => ['developer', 'regional_director'],
        ]));
    }

    public function test_redact_removes_known_and_formatted_numbers(): void
    {
        $known = '79001234567';
        $this->assertStringNotContainsString('9001234567', DeskClientPhonePrivacy::redactText('Клиент 9001234567 ждёт', $known));
        $this->assertStringNotContainsString('9001234567', DeskClientPhonePrivacy::redactText('тел +7 (900) 123-45-67', $known));
        $this->assertStringNotContainsString('9001234567', DeskClientPhonePrivacy::redactText('8 900 123 45 67 ок', null));
        $this->assertSame('Адрес только', DeskClientPhonePrivacy::redactText('Адрес только', $known));
    }

    public function test_history_drops_phone_keys(): void
    {
        $out = DeskClientPhonePrivacy::redactHistory([
            ['external_id' => '1', 'phone' => '79001234567', 'note' => 'звонок 79001234567'],
        ], '79001234567');
        $this->assertArrayNotHasKey('phone', $out[0]);
        $this->assertStringNotContainsString('9001234567', (string) $out[0]['note']);
    }
}
