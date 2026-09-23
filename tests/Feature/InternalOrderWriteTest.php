<?php

namespace Tests\Feature;

use App\Models\CrmConnection;
use App\Models\DeskOrderCache;
use App\Services\DeskOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class InternalOrderWriteTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-desk-internal-token';

    private CrmConnection $kp;

    protected function setUp(): void
    {
        parent::setUp();

        config(['desk.internal_token' => $this->token, 'desk.kp_crm_id' => 2]);

        $this->kp = CrmConnection::query()->create([
            'name' => 'kp-lead-centre',
            'type' => 'crm2_http',
            'base_url' => 'https://kp.test',
            'status' => 'active',
        ]);
        config(['desk.kp_crm_id' => (int) $this->kp->id]);
    }

    public function test_unauthorized_without_token(): void
    {
        $this->seedOrder('2651619', 'in_progress');

        $this->postJson('/desk/internal/orders/2651619/status', ['raw_status' => 'on_way'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthorized');
    }

    public function test_status_accepts_kp_prefix_id(): void
    {
        $this->seedOrder('2651619', 'on_way');

        $mock = Mockery::mock(DeskOrderService::class);
        $mock->shouldReceive('update')
            ->once()
            ->andReturnUsing(function (DeskOrderCache $cached, array $payload) {
                $cached->raw_status = $payload['raw_status'];
                $cached->status = 'in_progress';
                $cached->save();

                return $cached->fresh();
            });
        $this->app->instance(DeskOrderService::class, $mock);

        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/kp-2651619/status', ['raw_status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('raw_status', 'in_progress');
    }

    public function test_documents_upload_returns_document_meta(): void
    {
        Storage::fake('local');
        $this->seedOrder('2651619', 'in_progress');

        $doc = [
            'id' => 'kpurl:abc',
            'name' => 'receipt.jpg',
            'category' => 'receipts',
            'url' => 'https://kp.test/file.jpg',
            'mime' => 'image/jpeg',
        ];

        $mock = Mockery::mock(DeskOrderService::class);
        $mock->shouldReceive('uploadDocument')
            ->once()
            ->andReturnUsing(function (DeskOrderCache $cached) use ($doc) {
                $cached->documents = [$doc];
                $cached->save();

                return $cached->fresh();
            });
        $this->app->instance(DeskOrderService::class, $mock);

        $file = UploadedFile::fake()->image('receipt.png', 40, 40);

        $this->withToken($this->token)
            ->post('/desk/internal/orders/kp-2651619/documents', [
                'category' => 'receipts',
                'file' => $file,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('document.id', 'kpurl:abc')
            ->assertJsonPath('document.category', 'receipts');
    }

    public function test_close_success_and_repeat_already_final(): void
    {
        $this->seedOrder('2651619', 'in_progress', [
            'master_external_id' => '300',
            'master_name' => 'Мастер',
            'with_bso' => 1,
            'with_zip' => 0,
            'documents' => [['id' => '1', 'category' => 'contract']],
        ]);

        $mock = Mockery::mock(DeskOrderService::class);
        $mock->shouldReceive('update')->once()->andReturnUsing(fn (DeskOrderCache $c) => $c->fresh());
        $mock->shouldReceive('close')->once()->andReturnUsing(function (DeskOrderCache $cached) {
            $cached->raw_status = 'completed';
            $cached->status = 'closed';
            $cached->paid_amount = 5500;
            $cached->parts_amount = 1500;
            $cached->save();

            return ['order' => $cached->fresh(), 'calculation' => ['source' => 'kp']];
        });
        $this->app->instance(DeskOrderService::class, $mock);

        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/2651619/close', [
                'amountPaidRub' => 5500,
                'amountCompRub' => 1500,
                'masterComment' => 'готово',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('raw_status', 'completed');

        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/2651619/close', [
                'amountPaidRub' => 5500,
                'amountCompRub' => 1500,
                'masterComment' => 'ещё раз',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'order_already_final');
    }

    public function test_close_invalid_status_transition(): void
    {
        $this->seedOrder('2651619', 'pending');

        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/2651619/close', [
                'amountPaidRub' => 1000,
                'amountCompRub' => 0,
                'masterComment' => 'рано',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_status_transition');
    }

    public function test_sd_moves_to_in_progress_sd(): void
    {
        $this->seedOrder('2651619', 'in_progress');

        $mock = Mockery::mock(DeskOrderService::class);
        $mock->shouldReceive('update')
            ->once()
            ->withArgs(function (DeskOrderCache $cached, array $payload) {
                return ($payload['raw_status'] ?? null) === 'in_progress_sd'
                    && str_contains((string) ($payload['comment'] ?? ''), 'СД');
            })
            ->andReturnUsing(function (DeskOrderCache $cached) {
                $cached->raw_status = 'in_progress_sd';
                $cached->status = 'in_progress';
                $cached->save();

                return $cached->fresh();
            });
        $this->app->instance(DeskOrderService::class, $mock);

        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/kp-2651619/sd', [
                'masterComment' => 'нужны запчасти',
            ])
            ->assertOk()
            ->assertJsonPath('raw_status', 'in_progress_sd');
    }

    public function test_not_found(): void
    {
        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/9999999/status', ['raw_status' => 'on_way'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_reveal_office_from_cache_in_visit_status(): void
    {
        $this->seedOrder('2651619', 'in_progress', [
            'address_office' => 'кв / офис: 42',
            'call_at_local' => now()->addDays(2),
            'timezone' => 'Europe/Moscow',
        ]);

        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/kp-2651619/reveal-office')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('text', 'кв / офис: 42')
            ->assertJsonPath('from_cache', true);
    }

    public function test_reveal_office_forbidden_before_window_without_visit_status(): void
    {
        $this->seedOrder('2651619', 'pending', [
            'call_at_local' => now()->addDays(2),
            'timezone' => 'Europe/Moscow',
            'address_office' => null,
        ]);

        $this->withToken($this->token)
            ->postJson('/desk/internal/orders/2651619/reveal-office')
            ->assertForbidden()
            ->assertJsonPath('code', 'reveal_not_allowed');
    }

    /** @param  array<string, mixed>  $extra */
    private function seedOrder(string $externalId, string $rawStatus, array $extra = []): DeskOrderCache
    {
        return DeskOrderCache::query()->create(array_merge([
            'crm_id' => $this->kp->id,
            'external_id' => $externalId,
            'city_id' => 14,
            'city_name' => 'Псков',
            'status' => in_array($rawStatus, ['completed', 'closed'], true) ? 'closed' : 'in_progress',
            'raw_status' => $rawStatus,
            'client_name' => 'Тест',
            'phone' => '79001234567',
            'documents' => [],
        ], $extra));
    }
}
