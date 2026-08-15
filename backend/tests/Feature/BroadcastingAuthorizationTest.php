<?php

namespace Tests\Feature;

use App\Events\CollectionChanged;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BroadcastingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([CollectionChanged::class]);

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.app_id' => 'test-app',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
        ]);

        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }

    public function test_operator_can_subscribe_to_own_tenant_active_work_orders(): void
    {
        $tenant = Tenant::factory()->create();
        $operator = User::factory()->for($tenant)->create();
        Role::findOrCreate('Operator', 'web');
        $operator->assignRole('Operator');

        $this->actingAs($operator)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-col.{$tenant->id}.work_orders_active",
            ])
            ->assertOk();
    }

    public function test_operator_cannot_subscribe_to_admin_or_foreign_tenant_collections(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $operator = User::factory()->for($tenant)->create();
        Role::findOrCreate('Operator', 'web');
        $operator->assignRole('Operator');

        $this->actingAs($operator)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-col.{$tenant->id}.users",
            ])
            ->assertForbidden();

        $this->actingAs($operator)
            ->post('/broadcasting/auth', [
                'socket_id' => '1234.5678',
                'channel_name' => "private-col.{$foreignTenant->id}.work_orders_active",
            ])
            ->assertForbidden();
    }
}
