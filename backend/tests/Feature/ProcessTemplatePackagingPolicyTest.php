<?php

namespace Tests\Feature;

use App\Models\ProcessTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessTemplatePackagingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_freezes_the_configured_pallet_capacity(): void
    {
        $template = ProcessTemplate::factory()->create([
            'pallet_capacity_quantity' => 4800,
        ]);

        $snapshot = $template->toSnapshot();

        $this->assertSame([
            'pallet_capacity_quantity' => 4800,
        ], $snapshot['packaging_policy']);
    }
}
