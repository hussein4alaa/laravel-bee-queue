<?php

namespace G4T\BeeQueue\Events;

use G4T\BeeQueue\Job;

class JobProgress
{
    public function __construct(
        public readonly Job $job,
        public readonly int $progress,
    ) {}
}
