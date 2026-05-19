<?php

namespace G4T\BeeQueue\Http\Controllers;

use G4T\BeeQueue\QueueManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DashboardController extends Controller
{
    public function __construct(protected QueueManager $manager) {}

    // ── Main dashboard ────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $queueName = $request->query('queue', config('bee-queue.default', 'default'));
        $status    = $request->query('status', 'waiting');
        $queue     = $this->manager->queue($queueName);
        $stats     = $queue->stats();
        $jobs      = $this->getJobs($queue, $status);

        return view('bee-queue::dashboard', compact('stats', 'jobs', 'queueName', 'status'));
    }

    // ── Retry a failed job ────────────────────────────────────────────────

    public function retry(Request $request, string $queueName, string $jobId)
    {
        $queue = $this->manager->queue($queueName);
        $job   = $queue->getJob($jobId);

        if ($job) {
            $redis  = $this->getRedis($queue);
            $prefix = $this->getPrefix($queue);

            $redis->srem("{$prefix}:failed", $jobId);
            $redis->hset("{$prefix}:jobs", $jobId, $this->resetJobJson($job));
            $redis->rpush("{$prefix}:waiting", $jobId);
        }

        return redirect()->route('bee-queue.dashboard', ['queue' => $queueName, 'status' => 'waiting'])
            ->with('success', "Job #{$jobId} re-queued.");
    }

    // ── Delete a job ──────────────────────────────────────────────────────

    public function delete(Request $request, string $queueName, string $jobId)
    {
        $queue  = $this->manager->queue($queueName);
        $redis  = $this->getRedis($queue);
        $prefix = $this->getPrefix($queue);
        $status = $request->query('status', 'failed');

        $redis->hdel("{$prefix}:jobs", $jobId);
        $redis->lrem("{$prefix}:waiting", $jobId, 0);
        $redis->lrem("{$prefix}:active",  $jobId, 0);
        $redis->srem("{$prefix}:succeeded", $jobId);
        $redis->srem("{$prefix}:failed",    $jobId);
        $redis->zrem("{$prefix}:delayed",   $jobId);

        return redirect()->route('bee-queue.dashboard', ['queue' => $queueName, 'status' => $status])
            ->with('success', "Job #{$jobId} deleted.");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    protected function getJobs($queue, string $status): array
    {
        $redis  = $this->getRedis($queue);
        $prefix = $this->getPrefix($queue);

        $ids = match ($status) {
            'waiting'   => $redis->lrange("{$prefix}:waiting", 0, 49),
            'active'    => $redis->lrange("{$prefix}:active", 0, 49),
            'succeeded' => $redis->smembers("{$prefix}:succeeded"),
            'failed'    => $redis->smembers("{$prefix}:failed"),
            'delayed'   => $redis->zrange("{$prefix}:delayed", 0, 49),
            default     => [],
        };

        $jobs = [];
        foreach (array_slice((array) $ids, 0, 50) as $id) {
            $json = $redis->hget("{$prefix}:jobs", $id);
            if ($json) {
                $data          = json_decode($json, true);
                $data['id']    = $id;
                $jobs[]        = $data;
            }
        }

        return $jobs;
    }

    protected function getRedis($queue): mixed
    {
        $ref = new \ReflectionProperty($queue, 'redis');
        $ref->setAccessible(true);
        return $ref->getValue($queue);
    }

    protected function getPrefix($queue): string
    {
        $ref = new \ReflectionProperty($queue, 'prefix');
        $ref->setAccessible(true);
        return $ref->getValue($queue);
    }

    protected function resetJobJson($job): string
    {
        $ref = new \ReflectionObject($job);

        $data = $ref->getProperty('data');
        $data->setAccessible(true);

        return json_encode([
            'data'    => $data->getValue($job),
            'options' => [
                'timestamp'   => (int) round(microtime(true) * 1000),
                'stacktraces' => [],
                'retries'     => $job->retries,
                'backoff'     => $job->backoff,
                'retryDelay'  => $job->retryDelay,
                'timeout'     => $job->timeout,
                'delay'       => null,
            ],
            'status'   => 'waiting',
            'progress' => 0,
        ]);
    }
}
