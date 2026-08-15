<?php

namespace App\Services\Schedule;

use RuntimeException;

final class StaleScheduleProposal extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The schedule changed after this proposal was calculated.');
    }
}
