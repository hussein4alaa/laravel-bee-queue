<?php

namespace G4T\BeeQueue\Console;

use G4T\BeeQueue\QueueManager;
use Illuminate\Console\Command;

class StatsCommand extends Command
{
    protected $signature = 'bee-queue:stats {queue? : Queue name}';
    protected $description = 'Show health stats for a bee-queue';

    public function handle(QueueManager $manager): int
    {
        $stats = $manager->stats($this->argument('queue'));

        $this->table(
            ['Status', 'Count'],
            collect($stats)->map(fn ($count, $status) => [ucfirst($status), $count])->values()
        );

        return self::SUCCESS;
    }
}
