<?php

namespace G4T\BeeQueue;

use InvalidArgumentException;

class Job
{
    public ?string $id = null;
    public string $status = 'created';
    public int $progress = 0;
    public int $attempts = 0;
    public ?int $delay = null;
    public ?int $timeout = null;
    public int $retries = 0;
    public string $backoff = 'fixed';
    public int $retryDelay = 5;

    public function __construct(
        public readonly Queue $queue,
        public readonly array $data,
    ) {}

    // ── Chainable setters ──────────────────────────────────────────────────

    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function retries(int $count): static
    {
        $this->retries = $count;
        return $this;
    }

    public function backoff(string $type): static
    {
        if (! in_array($type, ['fixed', 'exponential'])) {
            throw new InvalidArgumentException("Backoff must be 'fixed' or 'exponential'.");
        }
        $this->backoff = $type;
        return $this;
    }

    public function retryDelay(int $seconds): static
    {
        $this->retryDelay = $seconds;
        return $this;
    }

    // ── Enqueue ───────────────────────────────────────────────────────────

    public function save(): static
    {
        $this->queue->enqueue($this);
        return $this;
    }

    // ── Progress ──────────────────────────────────────────────────────────

    public function reportProgress(int $progress): void
    {
        $this->progress = $progress;
        $this->queue->publishProgress($this, $progress);
    }

    // ── Serialization (matches bee-queue JS format) ───────────────────────

    public function toRedisJson(): string
    {
        return json_encode([
            'data'    => $this->data,
            'options' => [
                'timestamp'   => (int) round(microtime(true) * 1000),
                'stacktraces' => [],
                'retries'     => $this->retries,
                'backoff'     => $this->backoff,
                'retryDelay'  => $this->retryDelay,
                'timeout'     => $this->timeout,
                'delay'       => $this->delay,
            ],
            'status'   => $this->status,
            'progress' => $this->progress,
        ]);
    }

    public static function fromRedisJson(Queue $queue, string $id, string $json): static
    {
        $raw = json_decode($json, true);

        $job           = new static($queue, $raw['data'] ?? []);
        $job->id       = $id;
        $job->status   = $raw['status'] ?? 'created';
        $job->progress = (int) ($raw['progress'] ?? 0);

        $opts             = $raw['options'] ?? [];
        $job->retries     = (int) ($opts['retries'] ?? 0);
        $job->backoff     = $opts['backoff'] ?? 'fixed';
        $job->retryDelay  = (int) ($opts['retryDelay'] ?? 5);
        $job->timeout     = isset($opts['timeout']) ? (int) $opts['timeout'] : null;
        $job->delay       = isset($opts['delay']) ? (int) $opts['delay'] : null;

        return $job;
    }
}
