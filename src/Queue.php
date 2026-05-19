<?php

namespace G4T\BeeQueue;

use G4T\BeeQueue\Events\JobProgress;
use Illuminate\Support\Facades\Redis;

class Queue
{
    /** @var \Redis|\Predis\Client */
    protected mixed $redis;
    protected string $prefix;

    public function __construct(
        public readonly string $name,
        protected array $config = [],
    ) {
        $connection  = $config['redis_connection'] ?? 'default';
        $this->redis = Redis::connection($connection)->client();

        // Strip Laravel's global REDIS_PREFIX (set as OPT_PREFIX on PhpRedis)
        if ($this->redis instanceof \Redis) {
            $this->redis->setOption(\Redis::OPT_PREFIX, '');
        }

        $this->prefix = ($config['prefix'] ?? 'bq') . ':' . $name;
    }

    // ── Job factory ───────────────────────────────────────────────────────

    public function createJob(array $data): Job
    {
        return new Job($this, $data);
    }

    // ── Enqueue ───────────────────────────────────────────────────────────

    public function enqueue(Job $job): void
    {
        // Numeric auto-increment ID — matches bee-queue JS
        $id     = (string) $this->redis->incr("{$this->prefix}:id");
        $job->id = $id;

        if ($job->delay !== null && $job->delay > 0) {
            $job->status = 'created';
            $this->redis->hset("{$this->prefix}:jobs", $id, $job->toRedisJson());
            $runAt = time() + $job->delay;
            $this->redis->zadd("{$this->prefix}:delayed", $runAt, $id);
        } else {
            $job->status = 'created';
            $this->redis->hset("{$this->prefix}:jobs", $id, $job->toRedisJson());
            $this->redis->rpush("{$this->prefix}:waiting", $id);
        }
    }

    // ── Dequeue (blocking pop) ────────────────────────────────────────────

    public function dequeue(int $blockTimeout = 5): ?Job
    {
        $this->promoteDelayed();
        $this->tryCheckStall();

        $jobId = $this->redis->brpoplpush(
            "{$this->prefix}:waiting",
            "{$this->prefix}:active",
            $blockTimeout
        );

        if (! $jobId) {
            return null;
        }

        $json = $this->redis->hget("{$this->prefix}:jobs", $jobId);

        if (! $json) {
            return null;
        }

        $job = Job::fromRedisJson($this, $jobId, $json);
        $job->status   = 'active';
        $job->attempts++;

        $this->updateJobJson($job);

        return $job;
    }

    // ── Completion ────────────────────────────────────────────────────────

    public function markSucceeded(Job $job): void
    {
        $job->status = 'succeeded';
        $this->updateJobJson($job);
        $this->redis->lrem("{$this->prefix}:active", $job->id, 0);
        $this->redis->sadd("{$this->prefix}:succeeded", $job->id);

        if ($this->config['worker']['remove_on_success'] ?? false) {
            $this->redis->hdel("{$this->prefix}:jobs", $job->id);
        }
    }

    public function markFailed(Job $job): void
    {
        $job->status = 'failed';
        $this->updateJobJson($job);
        $this->redis->lrem("{$this->prefix}:active", $job->id, 0);
        $this->redis->sadd("{$this->prefix}:failed", $job->id);

        if ($this->config['worker']['remove_on_failure'] ?? false) {
            $this->redis->hdel("{$this->prefix}:jobs", $job->id);
        }
    }

    public function requeueForRetry(Job $job, int $delaySeconds): void
    {
        $job->status = 'waiting';
        $this->updateJobJson($job);
        $this->redis->lrem("{$this->prefix}:active", $job->id, 0);

        if ($delaySeconds > 0) {
            $runAt = time() + $delaySeconds;
            $this->redis->zadd("{$this->prefix}:delayed", $runAt, $job->id);
        } else {
            $this->redis->rpush("{$this->prefix}:waiting", $job->id);
        }
    }

    // ── Progress ──────────────────────────────────────────────────────────

    public function publishProgress(Job $job, int $progress): void
    {
        $this->updateJobJson($job);
        event(new JobProgress($job, $progress));
        $this->redis->publish("{$this->prefix}:job:{$job->id}:progress", $progress);
    }

    // ── Health stats ──────────────────────────────────────────────────────

    public function stats(): array
    {
        return [
            'waiting'   => $this->redis->llen("{$this->prefix}:waiting"),
            'active'    => $this->redis->llen("{$this->prefix}:active"),
            'succeeded' => $this->redis->scard("{$this->prefix}:succeeded"),
            'failed'    => $this->redis->scard("{$this->prefix}:failed"),
            'delayed'   => $this->redis->zcard("{$this->prefix}:delayed"),
        ];
    }

    // ── Job retrieval ─────────────────────────────────────────────────────

    public function getJob(string $id): ?Job
    {
        $json = $this->redis->hget("{$this->prefix}:jobs", $id);
        return $json ? Job::fromRedisJson($this, $id, $json) : null;
    }

    public function destroy(): void
    {
        $keys = $this->redis->keys("{$this->prefix}:*");
        if (! empty($keys)) {
            $this->redis->del($keys);
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    protected function updateJobJson(Job $job): void
    {
        $this->redis->hset("{$this->prefix}:jobs", $job->id, $job->toRedisJson());
    }

    protected function promoteDelayed(): void
    {
        $now  = time();
        $ids  = $this->redis->zrangebyscore("{$this->prefix}:delayed", '-inf', $now);

        foreach ($ids as $id) {
            if ($this->redis->zrem("{$this->prefix}:delayed", $id)) {
                $this->redis->rpush("{$this->prefix}:waiting", $id);
            }
        }
    }

    /**
     * Stall detection — only re-queues orphaned active jobs from crashed workers.
     * Uses SETNX + EXPIRE (compatible with all PhpRedis versions).
     * Safe for single-threaded workers: called before brpoplpush so active is
     * only populated by a previous (crashed) worker, not the current one.
     */
    protected function tryCheckStall(): void
    {
        $stallKey      = "{$this->prefix}:stallBlock";
        $stallInterval = (int) ($this->config['worker']['stall_interval'] ?? 5000);
        $ttl           = (int) ceil($stallInterval / 1000);

        // Acquire lock — only one worker runs the stall check per interval
        $acquired = $this->redis->setnx($stallKey, '1');
        if (! $acquired) {
            return;
        }

        $this->redis->expire($stallKey, $ttl);

        // Any jobs still in active here were left by a crashed worker — re-queue them
        $stalled = $this->redis->lrange("{$this->prefix}:active", 0, -1);
        foreach ($stalled as $id) {
            $this->redis->lrem("{$this->prefix}:active", $id, 0);
            $this->redis->rpush("{$this->prefix}:waiting", $id);
        }
    }
}
