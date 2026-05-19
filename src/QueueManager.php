<?php

namespace G4T\BeeQueue;

class QueueManager
{
    /** @var Queue[] */
    protected array $queues = [];

    public function __construct(protected array $config = []) {}

    public function queue(string $name = null): Queue
    {
        $name ??= $this->config['default'] ?? 'default';

        if (! isset($this->queues[$name])) {
            $this->queues[$name] = new Queue($name, $this->config);
        }

        return $this->queues[$name];
    }

    public function createJob(array $data, string $queue = null): Job
    {
        return $this->queue($queue)->createJob($data);
    }

    public function worker(string $queue = null): Worker
    {
        return new Worker($this->queue($queue), $this->config['worker'] ?? []);
    }

    public function stats(string $queue = null): array
    {
        return $this->queue($queue)->stats();
    }
}
