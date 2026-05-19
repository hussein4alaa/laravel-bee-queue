<?php

namespace G4T\BeeQueue\Events;

use G4T\BeeQueue\Job;

class JobSucceeded
{
    public function __construct(public readonly Job $job) {}
}
