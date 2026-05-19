# laravel-bee-queue

A simple, fast, Redis-backed job queue for Laravel — inspired by [bee-queue](https://github.com/bee-queue/bee-queue).

## Installation

```bash
composer require g4t/laravel-bee-queue
php artisan vendor:publish --tag=bee-queue-config
```

## Configuration

`.env` keys:

| Key | Default | Description |
|-----|---------|-------------|
| `BEE_QUEUE_DEFAULT` | `default` | Default queue name |
| `BEE_QUEUE_REDIS_CONNECTION` | `default` | Redis connection |
| `BEE_QUEUE_PREFIX` | `bq` | Redis key prefix |
| `BEE_QUEUE_CONCURRENCY` | `1` | Worker concurrency |
| `BEE_QUEUE_TIMEOUT` | `60` | Job timeout (seconds) |
| `BEE_QUEUE_RETRY_ATTEMPTS` | `3` | Max retries |
| `BEE_QUEUE_RETRY_BACKOFF` | `fixed` | `fixed` or `exponential` |
| `BEE_QUEUE_RETRY_DELAY` | `5` | Retry delay (seconds) |
| `BEE_QUEUE_REMOVE_ON_SUCCESS` | `false` | Delete job on success |
| `BEE_QUEUE_REMOVE_ON_FAILURE` | `false` | Delete job on failure |

## Basic Usage

### Creating & enqueuing a job

```php
use G4T\BeeQueue\Facades\BeeQueue;

// Create a job on the default queue
BeeQueue::createJob(['user_id' => 42, 'action' => 'send_welcome_email'])
    ->retries(3)
    ->backoff('exponential')
    ->retryDelay(5)
    ->delay(30)     // run 30 seconds from now
    ->timeout(60)
    ->save();

// Or on a named queue
BeeQueue::queue('emails')
    ->createJob(['to' => 'alice@example.com'])
    ->save();
```

### Processing jobs

**Via Artisan worker:**

```bash
php artisan bee-queue:work                     # default queue
php artisan bee-queue:work emails              # named queue
php artisan bee-queue:work --handler=App\Jobs\SendEmail
php artisan bee-queue:work --once              # process one job and exit
```

**Inline worker (e.g. in a command or test):**

```php
$worker = BeeQueue::worker('emails');

$worker->process(function ($job) {
    // $job->data contains your payload
    $job->reportProgress(50);

    // do work...
    $job->reportProgress(100);
});
```

### Handler class pattern

```php
namespace App\Jobs;

use G4T\BeeQueue\Contracts\JobContract;
use G4T\BeeQueue\Job;

class SendEmail implements JobContract
{
    public function __construct(private Job $job) {}

    public function handle(): void
    {
        $to = $this->job->data['to'];
        // ... send email
    }
}
```

Push it:

```php
BeeQueue::createJob(['class' => SendEmail::class, 'to' => 'alice@example.com'])->save();
```

### Queue health stats

```php
$stats = BeeQueue::stats();
// ['waiting' => 4, 'active' => 1, 'succeeded' => 120, 'failed' => 2, 'delayed' => 3]
```

```bash
php artisan bee-queue:stats
php artisan bee-queue:stats emails
```

## Events

Listen to these events in your `EventServiceProvider`:

| Event | Description |
|-------|-------------|
| `G4T\BeeQueue\Events\JobSucceeded` | Job completed successfully |
| `G4T\BeeQueue\Events\JobFailed` | Job exhausted all retries |
| `G4T\BeeQueue\Events\JobRetrying` | Job is being retried |
| `G4T\BeeQueue\Events\JobProgress` | Job reported progress |

```php
Event::listen(JobFailed::class, function ($event) {
    Log::error('Job failed', ['id' => $event->job->id, 'error' => $event->exception->getMessage()]);
});
```

## Redis Data Layout

```
bq:{queue}:jobs:{id}   — Hash  — job payload & metadata
bq:{queue}:waiting     — List  — IDs of waiting jobs (BRPOPLPUSH source)
bq:{queue}:active      — List  — IDs of active jobs
bq:{queue}:succeeded   — ZSet  — completed job IDs (scored by time)
bq:{queue}:failed      — ZSet  — failed job IDs
bq:{queue}:delayed     — ZSet  — delayed job IDs (scored by run-at timestamp)
```

## License

MIT
# laravel-bee-queue
# laravel-bee-queue
