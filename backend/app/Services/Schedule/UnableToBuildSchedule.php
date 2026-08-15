<?php

namespace App\Services\Schedule;

use RuntimeException;

final class UnableToBuildSchedule extends RuntimeException
{
    public static function incompleteDuration(int $stepNumber): self
    {
        return new self("Process step {$stepNumber} has no planning duration.");
    }

    public static function missingWorkstation(int $stepNumber): self
    {
        return new self("Process step {$stepNumber} has no eligible workstation on the selected line.");
    }

    public static function noCalendarWindow(int $stepNumber): self
    {
        return new self("Process step {$stepNumber} cannot be placed within the scheduling horizon.");
    }
}
