<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class SystemResetService
{
    private const COMMAND_TIMEOUT_SECONDS = 600;

    /**
     * Rebuild and seed the database in isolated CLI processes.
     *
     * Running these commands in the HTTP process leaves long-lived Octane
     * workers with stale migration and schema state after migrate:fresh.
     */
    public function resetDatabase(): void
    {
        $this->runArtisan(['migrate:fresh', '--force', '--no-interaction']);
        $this->runArtisan(['db:seed', '--force', '--no-interaction']);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runArtisan(array $arguments): void
    {
        $result = Process::path(base_path())
            ->timeout(self::COMMAND_TIMEOUT_SECONDS)
            ->run([PHP_BINARY, 'artisan', ...$arguments]);

        if ($result->successful()) {
            return;
        }

        throw new RuntimeException($this->failureMessage($arguments[0], $result));
    }

    private function failureMessage(string $command, ProcessResult $result): string
    {
        $details = trim($result->errorOutput()) ?: trim($result->output());

        return sprintf(
            'System reset command "%s" failed with exit code %d%s',
            $command,
            $result->exitCode(),
            $details !== '' ? ': '.$details : '.'
        );
    }
}
