<?php

namespace Tests\Unit;

use App\Support\DeskKpHydration;
use PHPUnit\Framework\TestCase;

class DeskKpHydrationTest extends TestCase
{
    public function test_list_row_without_customer_needs_card(): void
    {
        $this->assertTrue(DeskKpHydration::isIncomplete(null));
        $this->assertTrue(DeskKpHydration::isIncomplete('', 'уже есть описание'));
    }

    public function test_card_with_customer_id_is_complete(): void
    {
        $this->assertFalse(DeskKpHydration::isIncomplete('9001'));
        $this->assertFalse(DeskKpHydration::isIncomplete('9001', null, null));
    }
}
