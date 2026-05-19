<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Jobs;

use Visualbuilder\FilamentScreenshotCatalogue\Services\PageCaptureService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Captures every viewport × mode for one panel page and uploads the
 * shots to S3. Failure here doesn't poison sibling jobs in the batch —
 * the index builder works off the actual S3 listing so partial runs
 * still produce a useful catalogue.
 */
class CapturePageScreenshotsJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 300; // 5 min — Playwright login + 6 captures

    public int $backoff = 30;

    /**
     * @param  array{slug: string, url: string, label?: string, auth?: string}  $page
     * @param  array<int, array{name: string, width: int, height: int}>  $viewports
     * @param  array<int, string>  $modes
     */
    public function __construct(
        public string $panelKey,
        public array $page,
        public array $viewports,
        public array $modes,
        public string $env,
        public string $version,
        public bool $fullPage = false,
    ) {
        // Default to the dedicated screenshots queue so capture work
        // doesn't share a worker with fast app jobs (and so Horizon
        // hosts can give it a longer per-job timeout). Hosts override
        // by setting screenshot-catalogue.queue or passing --queue= on
        // the dispatch command.
        $this->onQueue((string) config('screenshot-catalogue.queue', 'screenshots'));
    }

    public function handle(PageCaptureService $service): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $service->capture(
            panelKey: $this->panelKey,
            pages: [$this->page],
            viewports: $this->viewports,
            modes: $this->modes,
            env: $this->env,
            version: $this->version,
            fullPage: $this->fullPage,
        );
    }

    public function failed(Throwable $exception): void
    {
        // Don't let a single page failure cancel the batch — the index
        // builder will simply omit shots for this slug. Sibling jobs and
        // the final RebuildScreenshotIndexJob continue.
        \Log::warning('CapturePageScreenshotsJob failed', [
            'panel' => $this->panelKey,
            'page' => $this->page['slug'] ?? '(unknown)',
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Stable per-job tag so logs/Horizon group failures by sitemap entry.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'screenshots',
            "panel:{$this->panelKey}",
            "page:{$this->page['slug']}",
        ];
    }
}
