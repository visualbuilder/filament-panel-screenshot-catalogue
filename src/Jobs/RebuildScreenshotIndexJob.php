<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Jobs;

use Visualbuilder\FilamentScreenshotCatalogue\Services\IndexBuilderService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Rebuilds the public index.html for a screenshot run. Triggered as the
 * `finally` callback on the capture batch — runs whether or not every
 * page captured cleanly, so a partial run still produces a viewable
 * catalogue. Can also be re-dispatched ad hoc if the template changes.
 */
class RebuildScreenshotIndexJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public string $panelKey,
        public string $env,
        public string $version,
    ) {
        $this->onQueue((string) config('screenshot-catalogue.queue', 'screenshots'));
    }

    public function handle(IndexBuilderService $service): void
    {
        $service->rebuild($this->panelKey, $this->env, $this->version);
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'screenshots',
            'index-rebuild',
            "panel:{$this->panelKey}",
        ];
    }
}
