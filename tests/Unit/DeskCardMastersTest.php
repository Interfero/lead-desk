<?php

namespace Tests\Unit;

use App\Support\DeskCardMasters;
use PHPUnit\Framework\TestCase;

class DeskCardMastersTest extends TestCase
{
    public function test_keeps_list_when_current_already_present(): void
    {
        $list = [
            ['id' => '10', 'name' => 'Иван'],
            ['id' => '11', 'name' => 'Пётр'],
        ];

        $this->assertSame($list, DeskCardMasters::withCurrent($list, '11', 'Пётр'));
    }

    public function test_prepends_current_master_if_missing(): void
    {
        $out = DeskCardMasters::withCurrent(
            [['id' => '10', 'name' => 'Иван']],
            '22',
            'Мария'
        );

        $this->assertSame('22', $out[0]['id']);
        $this->assertSame('Мария', $out[0]['name']);
        $this->assertCount(2, $out);
    }

    public function test_uses_fallback_name_when_empty(): void
    {
        $out = DeskCardMasters::withCurrent([], '7', '');

        $this->assertSame([['id' => '7', 'name' => '#7']], $out);
    }

    public function test_empty_id_does_not_add_row(): void
    {
        $this->assertSame([], DeskCardMasters::withCurrent([], '', 'Иван'));
        $this->assertSame([], DeskCardMasters::withCurrent([], null, 'Иван'));
    }

    public function test_from_kp_rows_keeps_only_active(): void
    {
        $out = DeskCardMasters::fromKpRows([
            ['kp_employee_id' => 10, 'name' => 'Иван', 'is_active' => true],
            ['kp_employee_id' => 11, 'name' => 'Пётр', 'is_active' => false],
            ['kp_employee_id' => 0, 'name' => 'Пустой', 'is_active' => true],
        ]);

        $this->assertSame([['id' => '10', 'name' => 'Иван']], $out);
    }

    public function test_merge_keeps_cron_masters_when_live_select_is_smaller(): void
    {
        $cron = [
            ['id' => '10', 'name' => 'Иван'],
            ['id' => '11', 'name' => 'Пётр'],
        ];
        $live = [
            ['id' => '11', 'name' => 'Пётр'],
        ];

        $out = DeskCardMasters::merge($cron, $live);

        $this->assertCount(2, $out);
        $ids = array_column($out, 'id');
        $this->assertContains('10', $ids);
        $this->assertContains('11', $ids);
    }
}
