<?php

namespace G4T\BeeQueue\Events;

use G4T\BeeQueue\Job;
use Throwable;

class JobRetrying
{
    public function __construct(
        public readonly Job $job,
        public readonly Throwable $exception,
        public readonly int $nextAttempt,
    ) {}
}
