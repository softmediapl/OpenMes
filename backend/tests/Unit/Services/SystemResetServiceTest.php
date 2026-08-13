<?php

namespace Tests\Unit\Services;

use App\Services\SystemResetService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class SystemResetServiceTest extends TestCase
{
    public function test_it_rebuilds_and_seeds_in_separate_cli_processes(): void
    {
        Process::fake();

        app(SystemResetService::class)->resetDatabase();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            PHP_BINARY,
            'artisan',
            'migrate:fresh',
            '--force',
            '--no-interaction',
        ]);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            PHP_BINARY,
            'artisan',
            'db:seed',
            '--force',
            '--no-interaction',
        ]);
        Process::assertRanTimes(fn (PendingProcess $process): bool => true, 2);
    }

    public function test_it_does_not_seed_after_a_failed_migration(): void
    {
        Process::fake([
            '*migrate:fresh*' => Process::result(
                errorOutput: 'migration failed',
                exitCode: 1,
            ),
        ]);

        try {
            app(SystemResetService::class)->resetDatabase();
            $this->fail('The reset should fail when migrate:fresh fails.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('migrate:fresh', $exception->getMessage());
            $this->assertStringContainsString('migration failed', $exception->getMessage());
        }

        Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === [
            PHP_BINARY,
            'artisan',
            'db:seed',
            '--force',
            '--no-interaction',
        ]);
    }
}
