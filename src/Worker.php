<?php

namespace G4T\BeeQueue;

use G4T\BeeQueue\Contracts\JobContract;
use G4T\BeeQueue\Events\JobFailed;
use G4T\BeeQueue\Events\JobRetrying;
use G4T\BeeQueue\Events\JobSucceeded;
use Throwable;

class Worker
{
    protected bool $shouldStop = false;
    protected int $concurrency;
    protected int $timeout;

    public function __construct(
        protected Queue $queue,
        protected array $config = [],
    ) {
        $this->concurrency = $config['concurrency'] ?? 1;
        $this->timeout     = $config['timeout'] ?? 60;
    }

    /**
     * Register a handler closure and start processing.
     *
     * @param callable(Job): void $handler
     */
    public function process(callable $handler): void
    {
        $this->listenForSignals();

        while (! $this->shouldStop) {
            $job = $this->queue->dequeue(blockTimeout: 5);

            if ($job === null) {
                continue;
            }

            $this->handleJob($job, $handler);
        }
    }

    /**
     * Process exactly one job and return.
     */
    public function processOne(callable $handler): bool
    {
        $job = $this->queue->dequeue(blockTimeout: 0);

        if ($job === null) {
            return false;
        }

        $this->handleJob($job, $handler);
        return true;
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    // ── Internal ──────────────────────────────────────────────────────────

    protected function handleJob(Job $job, callable $handler): void
    {
        try {
            $timeoutSeconds = $job->timeout ?? $this->timeout;
            $this->runWithTimeout($timeoutSeconds, fn () => $handler($job));

            $this->queue->markSucceeded($job);
            event(new JobSucceeded($job));
        } catch (Throwable $e) {
            $this->handleFailure($job, $e);
        }
    }

    protected function handleFailure(Job $job, Throwable $e): void
    {
        if ($job->attempts <= $job->retries) {
            $delay = $this->computeBackoff($job);
            event(new JobRetrying($job, $e, $job->attempts + 1));
            $this->queue->requeueForRetry($job, $delay);
        } else {
            $this->queue->markFailed($job);
            event(new JobFailed($job, $e));
        }
    }

    protected function computeBackoff(Job $job): int
    {
        return match ($job->backoff) {
            'exponential' => $job->retryDelay * (2 ** ($job->attempts - 1)),
            default       => $job->retryDelay,
        };
    }

    protected function runWithTimeout(int $seconds, callable $callback): void
    {
        if (function_exists('pcntl_alarm')) {
            pcntl_signal(SIGALRM, function () {
                throw new \RuntimeException('Job timed out.');
            });
            pcntl_alarm($seconds);
        }

        try {
            $callback();
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
        }
    }

    protected function listenForSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT,  fn () => $this->stop());
    }
}
