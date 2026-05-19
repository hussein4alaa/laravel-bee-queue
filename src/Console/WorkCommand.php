<?php

namespace G4T\BeeQueue\Console;

use G4T\BeeQueue\QueueManager;
use Illuminate\Console\Command;

class WorkCommand extends Command
{
    protected $signature = 'bee-queue:work
        {queue?           : Queue name (default: from config)}
        {--handler=       : Fully-qualified class name implementing JobContract}
        {--concurrency=1  : Number of concurrent jobs}
        {--timeout=60     : Seconds before a job is considered timed out}
        {--once           : Process a single job and exit}';

    protected $description = 'Start a bee-queue worker';

    public function handle(QueueManager $manager): int
    {
        $queueName    = $this->argument('queue');
        $handlerClass = $this->option('handler');
        $once         = $this->option('once');
        $label        = $queueName ?: 'default';

        if ($handlerClass && ! class_exists($handlerClass)) {
            $this->error("Handler class [{$handlerClass}] not found.");
            return self::FAILURE;
        }

        $handler = $handlerClass
            ? fn ($job) => app($handlerClass, ['job' => $job])->handle()
            : fn ($job) => $this->defaultHandler($job);

        $worker = $manager->worker($queueName);

        $this->info("Starting bee-queue worker on [{$label}]...");

        if ($once) {
            $processed = $worker->processOne($handler);
            $this->info($processed ? 'Job processed.' : 'No jobs in queue.');
            return self::SUCCESS;
        }

        $worker->process($handler);
        return self::SUCCESS;
    }

    protected function defaultHandler($job): void
    {
        $class = $job->data['class'] ?? null;

        if ($class && class_exists($class)) {
            $instance = app($class, ['job' => $job]);
            $instance->handle();
            return;
        }

        $this->warn("No handler found for job [{$job->id}]. Override with --handler=YourClass");
    }
}
