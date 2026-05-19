<?php

namespace G4T\BeeQueue;

use G4T\BeeQueue\Events\JobProgress;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

class Queue
{
    protected Connection $redis;
    protected string $prefix;

    public function __construct(
        public readonly string $name,
        protected array $config = [],
    ) {
        $connection      = $config['redis_connection'] ?? 'default';
        $this->redis     = Redis::connection($connection);
        $this->prefix    = ($config['prefix'] ?? 'bq') . ':' . $name;
    }

    // ── Job factory ───────────────────────────────────────────────────────

    public function createJob(array $data): Job
    {
        return new Job($this, $data);
    }

    // ── Enqueue ───────────────────────────────────────────────────────────

    public function enqueue(Job $job): void
    {
        $this->redis->hmset("{$this->prefix}:jobs:{$job->id}", $job->toArray());

        if ($job->delay !== null && $job->delay > 0) {
            $runAt = time() + $job->delay;
            $this->redis->zadd("{$this->prefix}:delayed", $runAt, $job->id);
            $job->status = 'delayed';
        } else {
            $this->redis->lpush("{$this->prefix}:waiting", $job->id);
            $job->status = 'waiting';
        }

        // Update status in hash
        $this->redis->hset("{$this->prefix}:jobs:{$job->id}", 'status', $job->status);
    }

    // ── Dequeue (blocking pop) ────────────────────────────────────────────

    public function dequeue(int $blockTimeout = 5): ?Job
    {
        // Promote any delayed jobs whose time has come
        $this->promoteDelayed();

        $result = $this->redis->brpoplpush(
            "{$this->prefix}:waiting",
            "{$this->prefix}:active",
            $blockTimeout
        );

        if (! $result) {
            return null;
        }

        $jobId = $result;
        $raw   = $this->redis->hgetall("{$this->prefix}:jobs:{$jobId}");

        if (empty($raw)) {
            return null;
        }

        $job = Job::fromArray($this, $raw);
        $job->status = 'active';
        $job->attempts++;
        $this->redis->hset("{$this->prefix}:jobs:{$jobId}", 'status', 'active');
        $this->redis->hset("{$this->prefix}:jobs:{$jobId}", 'attempts', $job->attempts);

        return $job;
    }

    // ── Completion ────────────────────────────────────────────────────────

    public function markSucceeded(Job $job): void
    {
        $job->status = 'succeeded';
        $this->redis->hset("{$this->prefix}:jobs:{$job->id}", 'status', 'succeeded');
        $this->redis->lrem("{$this->prefix}:active", 0, $job->id);
        $this->redis->zadd("{$this->prefix}:succeeded", time(), $job->id);

        if ($this->config['worker']['remove_on_success'] ?? false) {
            $this->removeJob($job->id);
        }
    }

    public function markFailed(Job $job): void
    {
        $job->status = 'failed';
        $this->redis->hset("{$this->prefix}:jobs:{$job->id}", 'status', 'failed');
        $this->redis->lrem("{$this->prefix}:active", 0, $job->id);
        $this->redis->zadd("{$this->prefix}:failed", time(), $job->id);

        if ($this->config['worker']['remove_on_failure'] ?? false) {
            $this->removeJob($job->id);
        }
    }

    public function requeueForRetry(Job $job, int $delaySeconds): void
    {
        $job->status = 'waiting';
        $this->redis->hset("{$this->prefix}:jobs:{$job->id}", 'status', 'waiting');
        $this->redis->hset("{$this->prefix}:jobs:{$job->id}", 'attempts', $job->attempts);
        $this->redis->lrem("{$this->prefix}:active", 0, $job->id);

        if ($delaySeconds > 0) {
            $runAt = time() + $delaySeconds;
            $this->redis->zadd("{$this->prefix}:delayed", $runAt, $job->id);
        } else {
            $this->redis->lpush("{$this->prefix}:waiting", $job->id);
        }
    }

    // ── Progress ──────────────────────────────────────────────────────────

    public function publishProgress(Job $job, int $progress): void
    {
        event(new JobProgress($job, $progress));
        $this->redis->publish("{$this->prefix}:job:{$job->id}:progress", $progress);
    }

    // ── Health stats ──────────────────────────────────────────────────────

    public function stats(): array
    {
        return [
            'waiting'   => $this->redis->llen("{$this->prefix}:waiting"),
            'active'    => $this->redis->llen("{$this->prefix}:active"),
            'succeeded' => $this->redis->zcard("{$this->prefix}:succeeded"),
            'failed'    => $this->redis->zcard("{$this->prefix}:failed"),
            'delayed'   => $this->redis->zcard("{$this->prefix}:delayed"),
        ];
    }

    // ── Job retrieval ─────────────────────────────────────────────────────

    public function getJob(string $id): ?Job
    {
        $raw = $this->redis->hgetall("{$this->prefix}:jobs:{$id}");
        return empty($raw) ? null : Job::fromArray($this, $raw);
    }

    public function destroy(): void
    {
        $keys = $this->redis->keys("{$this->prefix}:*");
        if (! empty($keys)) {
            $this->redis->del($keys);
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    protected function promoteDelayed(): void
    {
        $now  = time();
        $jobs = $this->redis->zrangebyscore("{$this->prefix}:delayed", '-inf', $now);

        foreach ($jobs as $jobId) {
            $moved = $this->redis->zrem("{$this->prefix}:delayed", $jobId);
            if ($moved) {
                $this->redis->lpush("{$this->prefix}:waiting", $jobId);
                $this->redis->hset("{$this->prefix}:jobs:{$jobId}", 'status', 'waiting');
            }
        }
    }

    protected function removeJob(string $id): void
    {
        $this->redis->del("{$this->prefix}:jobs:{$id}");
    }
}
