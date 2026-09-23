<?php

namespace Tests\Feature;

use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskHistorySyncSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CrmConnection::query()->create([
            'name' => 'kp-lead-centre',
            'type' => 'crm2_http',
            'base_url' => 'https://kp.test',
            'status' => 'active',
        ]);
        Crm2CityCredential::query()->create([
            'city_id' => 14,
            'city_name' => 'Псков',
            'login' => 'test@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);
    }

    public function test_city_manager_can_queue_a_30_day_history_sync_for_an_allowed_city(): void
    {
        $this->withSession(['desk_user' => ['id' => 7, 'roles' => ['developer'], 'city_ids' => [14]]])
            ->post(route('desk.settings.history-sync', ['cityId' => 14, 'days' => 30]))
            ->assertRedirect(route('desk.settings'))
            ->assertSessionHas('success', 'История KP за 30 дн. поставлена в очередь.');

        $this->assertDatabaseHas('desk_history_sync_runs', [
            'city_id' => 14,
            'days' => 30,
            'state' => 'queued',
        ]);
    }

    public function test_city_manager_can_queue_a_one_year_history_sync_for_an_allowed_city(): void
    {
        $this->withSession(['desk_user' => ['id' => 7, 'roles' => ['developer'], 'city_ids' => [14]]])
            ->post(route('desk.settings.history-sync', ['cityId' => 14, 'days' => 365]))
            ->assertRedirect(route('desk.settings'))
            ->assertSessionHas('success', 'История KP за 1 год поставлена в очередь.');

        $this->assertDatabaseHas('desk_history_sync_runs', [
            'city_id' => 14,
            'days' => 365,
            'state' => 'queued',
        ]);
    }

    public function test_city_manager_can_queue_a_two_year_history_sync_for_an_allowed_city(): void
    {
        $this->withSession(['desk_user' => ['id' => 7, 'roles' => ['developer'], 'city_ids' => [14]]])
            ->post(route('desk.settings.history-sync', ['cityId' => 14, 'days' => 730]))
            ->assertRedirect(route('desk.settings'))
            ->assertSessionHas('success', 'История KP за 2 года поставлена в очередь.');

        $this->assertDatabaseHas('desk_history_sync_runs', [
            'city_id' => 14,
            'days' => 730,
            'state' => 'queued',
        ]);
    }

    public function test_city_manager_cannot_queue_history_for_an_inaccessible_city(): void
    {
        $this->withSession(['desk_user' => ['id' => 7, 'roles' => ['developer'], 'city_ids' => [15]]])
            ->post('/desk/settings/history-sync/14/30')
            ->assertForbidden();

        $this->assertDatabaseCount('desk_history_sync_runs', 0);
    }
}
