<?php

namespace Tests\Unit;

use App\Support\DeskMasterCalc;
use PHPUnit\Framework\TestCase;

class DeskMasterCalcTest extends TestCase
{
    public function test_non_core_3600_matches_crm(): void
    {
        $calc = DeskMasterCalc::compute(3600, 0, 'first', true);

        $this->assertSame(40, $calc['master_percent']);
        $this->assertSame(1440, $calc['master_salary']);
        $this->assertSame(2160, $calc['amount_to_pay']);
    }

    public function test_profile_3600_matches_crm(): void
    {
        $calc = DeskMasterCalc::compute(3600, 0, 'first', false, false, 'core');

        $this->assertSame(30, $calc['master_percent']);
        $this->assertSame(1080, $calc['master_salary']);
    }

    public function test_long_trip_overrides_non_core(): void
    {
        $calc = DeskMasterCalc::compute(3600, 0, 'first', true, true, 'non_core');

        $this->assertSame(50, $calc['master_percent']);
    }

    public function test_other_partner_order(): void
    {
        $calc = DeskMasterCalc::compute(10000, 0, 'first', false, false, 'other', false, true);

        $this->assertSame(40, $calc['master_percent']);
    }
}
