<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Services\Operator\PanelCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PanelCredentialServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_pin_is_hashed_searchable_and_has_configured_length(): void
    {
        $user = User::factory()->create();
        $service = app(PanelCredentialService::class);

        $pin = $service->generate($user);

        $this->assertMatchesRegularExpression('/^\d{9}$/', $pin);
        $this->assertTrue(Hash::check($pin, $user->fresh()->pin));
        $this->assertSame($user->id, $service->find($pin, $user->tenant_id)?->id);
        $this->assertNotSame($pin, $user->fresh()->pin_lookup);
    }

    public function test_lookup_is_unique_within_tenant(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create(['tenant_id' => $first->tenant_id]);
        $service = app(PanelCredentialService::class);
        $service->set($first, '123456789');

        $this->expectException(ValidationException::class);
        $service->set($second, '123456789');
    }

    public function test_legacy_pin_remains_usable_without_being_reinterpreted(): void
    {
        $user = User::factory()->create(['pin' => Hash::make('123456'), 'pin_lookup' => null]);

        $this->assertTrue(Hash::check('123456', $user->pin));
        $this->assertNull(app(PanelCredentialService::class)->find('123456', $user->tenant_id));
    }
}
