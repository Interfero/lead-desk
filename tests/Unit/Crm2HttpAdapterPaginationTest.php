<?php

namespace Tests\Unit;

use App\Adapters\Crm2HttpAdapter;
use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class Crm2HttpAdapterPaginationTest extends TestCase
{
    public function test_history_page_includes_all_statuses_and_returns_the_next_kp_page(): void
    {
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame('11.09.2026 00:00 - 18.09.2026 23:59', $query['CustomerRequestSearch']['dates']);
            $this->assertSame(
                [1, 4, 8, 9, 32, 1001, 1002, 1003, 64, 128, 1020, 20, 3, 48],
                array_map('intval', $query['CustomerRequestSearch']['statuses'])
            );

            $page = (int) ($query['page'] ?? 1);

            return Http::response($this->ordersPage($page, $page === 1));
        });

        $adapter = $this->adapter();
        $from = CarbonImmutable::parse('2026-09-11');
        $to = CarbonImmutable::parse('2026-09-18');

        $first = $adapter->fetchHistoryPage($from, $to, 1);
        $second = $adapter->fetchHistoryPage($from, $to, 2);

        $this->assertSame(['1001'], array_column($first['orders'], 'external_id'));
        $this->assertSame(2, $first['next_page']);
        $this->assertSame(['1002'], array_column($second['orders'], 'external_id'));
        $this->assertSame('completed', $second['orders'][0]['raw_status']);
        $this->assertSame(14, $second['orders'][0]['city_id']);
        $this->assertNull($second['next_page']);
    }

    public function test_history_page_rejects_a_non_advancing_pager(): void
    {
        Http::fake([
            'https://kp.test/*' => Http::response($this->ordersPageWithNextDataPage(0)),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Некорректная следующая страница истории KP.');

        $this->adapter()->fetchHistoryPage(
            CarbonImmutable::parse('2026-09-11'),
            CarbonImmutable::parse('2026-09-18'),
            1
        );
    }

    private function adapter(): Crm2HttpAdapter
    {
        return new class(
            new CrmConnection(['base_url' => 'https://kp.test', 'timeout' => 30]),
            new Crm2CityCredential([
                'id' => 987654,
                'city_id' => 14,
                'city_name' => 'Псков',
                'login' => 'test@example.test',
                'password' => 'password',
            ]),
        ) extends Crm2HttpAdapter {
            public function authenticate(): void {}
        };
    }

    private function ordersPage(int $page, bool $hasNextPage): string
    {
        $id = $page === 1 ? '1001' : '1002';
        $status = $page === 1 ? 'В работе' : 'Готов';
        $pager = $hasNextPage
            ? '<ul class="pagination"><li class="page-item next"><a href="/admin/domain/customer-request/index?page=2" data-page="1">»</a></li></ul>'
            : '<ul class="pagination"><li class="page-item next disabled"><span>»</span></li></ul>';

        return <<<HTML
            <table class="table">
                <thead><tr><th>ID</th><th>Статус</th><th>Мастер</th></tr></thead>
                <tbody><tr data-key="{$id}"><td>{$id}</td><td>{$status}</td><td>Тугай Никита</td></tr></tbody>
            </table>
            {$pager}
            HTML;
    }

    private function ordersPageWithNextDataPage(int $dataPage): string
    {
        return <<<HTML
            <table class="table">
                <thead><tr><th>ID</th><th>Статус</th><th>Мастер</th></tr></thead>
                <tbody><tr data-key="1001"><td>1001</td><td>В работе</td><td>Тугай Никита</td></tr></tbody>
            </table>
            <ul class="pagination"><li class="page-item next"><a data-page="{$dataPage}">»</a></li></ul>
            HTML;
    }
}
