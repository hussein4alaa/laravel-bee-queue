<?php

namespace G4T\BeeQueue\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \G4T\BeeQueue\Queue   queue(string $name = null)
 * @method static \G4T\BeeQueue\Job     createJob(array $data, string $queue = null)
 * @method static \G4T\BeeQueue\Worker  worker(string $queue = null)
 * @method static array                      stats(string $queue = null)
 *
 * @see \G4T\BeeQueue\QueueManager
 */
class BeeQueue extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'bee-queue';
    }
}
