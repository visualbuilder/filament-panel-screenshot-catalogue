<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Flushes everything sitting on the screenshots queue: pending jobs,
 * reserved-but-not-yet-running jobs, the notify list, and the delayed
 * sorted set. Optional flags also cancel in-flight Bus batches and
 * clear matching `failed_jobs` rows so the next dispatch starts from
 * a known-empty state.
 *
 * Useful when a bad descriptor (wrong domain / bad password) causes
 * dozens of capture jobs to queue up failures faster than you can
 * triage them — easier to clear and re-dispatch than retry.
 */
class FlushScreenshotQueueCommand extends Command
{
    protected $signature = 'screenshot:flush-queue
        {--queue= : Queue name to flush (defaults to screenshot-catalogue.queue config, typically `screenshots`)}
        {--with-batches : Also cancel any in-flight Bus batches that haven\'t finished}
        {--with-failed : Also remove failed_jobs rows queued on this queue}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Clear pending / reserved / delayed screenshot capture jobs from the queue.';

    public function handle(): int
    {
        $queue = (string) ($this->option('queue') ?: config('screenshot-catalogue.queue', 'screenshots'));

        $pending = (int) Redis::llen("queues:{$queue}");
        $reserved = (int) Redis::zcard("queues:{$queue}:reserved");
        $delayed = (int) Redis::zcard("queues:{$queue}:delayed");

        $activeBatches = $this->option('with-batches')
            ? DB::table('job_batches')->whereNull('finished_at')->whereNull('cancelled_at')->count()
            : 0;

        $failedRows = $this->option('with-failed')
            ? DB::table('failed_jobs')->where('queue', $queue)->count()
            : 0;

        $this->table(
            ['target', 'count'],
            [
                ["queues:{$queue}", $pending],
                ["queues:{$queue}:reserved", $reserved],
                ["queues:{$queue}:delayed", $delayed],
                ['active batches (--with-batches)', $activeBatches],
                ['failed_jobs (--with-failed)', $failedRows],
            ],
        );

        if (! $this->option('force') && ! $this->confirm("Flush the above from queue `{$queue}`?", true)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        Redis::del("queues:{$queue}");
        Redis::del("queues:{$queue}:reserved");
        Redis::del("queues:{$queue}:notify");
        Redis::del("queues:{$queue}:delayed");

        if ($this->option('with-batches')) {
            $cancelled = 0;
            foreach (DB::table('job_batches')->whereNull('finished_at')->whereNull('cancelled_at')->pluck('id') as $id) {
                Bus::findBatch($id)?->cancel();
                $cancelled++;
            }
            $this->info("Cancelled {$cancelled} active batch(es).");
        }

        if ($this->option('with-failed')) {
            $deleted = DB::table('failed_jobs')->where('queue', $queue)->delete();
            $this->info("Removed {$deleted} failed_jobs row(s) on queue `{$queue}`.");
        }

        $this->info("Queue `{$queue}` flushed.");

        return self::SUCCESS;
    }
}
