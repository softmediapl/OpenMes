<?php

namespace Tests\Unit\Services;

use App\Services\WorkOrder\BatchSizingService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BatchSizingServiceTest extends TestCase
{
    private BatchSizingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BatchSizingService::class);
    }

    public function test_missing_policy_preserves_manual_batch_creation(): void
    {
        $this->assertSame([], $this->service->split(3000, null));
        $this->assertSame([], $this->service->split(3000, ['preferred_quantity' => null]));
    }

    public function test_splits_released_quantity_into_preferred_batches(): void
    {
        $targets = $this->service->split(3000, $this->policy());

        $this->assertCount(15, $targets);
        $this->assertSame(array_fill(0, 15, 200.0), $targets);
        $this->assertSame(3000.0, array_sum($targets));
    }

    public function test_appends_the_remainder_as_a_partial_final_batch(): void
    {
        $targets = $this->service->split(3050, $this->policy());

        $this->assertCount(16, $targets);
        $this->assertSame(50.0, $targets[15]);
        $this->assertSame(3050.0, array_sum($targets));
    }

    public function test_rejects_an_indivisible_order_when_partial_batches_are_disabled(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('partial final batches are disabled');

        $this->service->split(3050, $this->policy(false));
    }

    #[DataProvider('invalidPolicies')]
    public function test_rejects_invalid_snapshot_policies(array $policy, string $message): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($message);

        $this->service->split(1000, $policy);
    }

    public static function invalidPolicies(): array
    {
        return [
            'minimum above preferred' => [[
                'preferred_quantity' => 200,
                'minimum_quantity' => 250,
            ], 'Minimum batch quantity'],
            'maximum below preferred' => [[
                'preferred_quantity' => 200,
                'maximum_quantity' => 150,
            ], 'Maximum batch quantity'],
            'invalid increment' => [[
                'preferred_quantity' => 200,
                'quantity_multiple' => 30,
            ], 'multiple of the configured increment'],
        ];
    }

    private function policy(bool $allowPartial = true): array
    {
        return [
            'preferred_quantity' => 200,
            'minimum_quantity' => 100,
            'maximum_quantity' => 200,
            'quantity_multiple' => 50,
            'allow_partial_final_batch' => $allowPartial,
        ];
    }
}
