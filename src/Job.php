<?php

namespace G4T\BeeQueue;

use InvalidArgumentException;

class Job
{
    public readonly string $id;
    public string $status = 'created'; // created|waiting|active|succeeded|failed
    public int $attempts = 0;
    public ?int $delay = null;     // seconds from now
    public ?int $timeout = null;   // seconds
    public int $retries = 0;
    public string $backoff = 'fixed';
    public int $retryDelay = 5;
    public mixed $result = null;

    public function __construct(
        public readonly Queue $queue,
        public readonly array $data,
        ?string $id = null,
    ) {
        $this->id = $id ?? $this->generateId();
    }

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

    // ── Progress (reported from inside a handler) ─────────────────────────

    public function reportProgress(int $progress): void
    {
        $this->queue->publishProgress($this, $progress);
    }

    // ── Serialization ─────────────────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'data'       => json_encode($this->data),
            'attempts'   => $this->attempts,
            'delay'      => $this->delay,
            'timeout'    => $this->timeout,
            'retries'    => $this->retries,
            'backoff'    => $this->backoff,
            'retryDelay' => $this->retryDelay,
            'status'     => $this->status,
        ];
    }

    public static function fromArray(Queue $queue, array $raw): static
    {
        $job = new static(
            queue: $queue,
            data: json_decode($raw['data'] ?? '[]', true),
            id: $raw['id'],
        );
        $job->attempts   = (int) ($raw['attempts'] ?? 0);
        $job->delay      = isset($raw['delay']) ? (int) $raw['delay'] : null;
        $job->timeout    = isset($raw['timeout']) ? (int) $raw['timeout'] : null;
        $job->retries    = (int) ($raw['retries'] ?? 0);
        $job->backoff    = $raw['backoff'] ?? 'fixed';
        $job->retryDelay = (int) ($raw['retryDelay'] ?? 5);
        $job->status     = $raw['status'] ?? 'waiting';

        return $job;
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
