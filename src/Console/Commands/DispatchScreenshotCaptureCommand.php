<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Visualbuilder\FilamentScreenshotCatalogue\Jobs\CapturePageScreenshotsJob;
use Visualbuilder\FilamentScreenshotCatalogue\Jobs\RebuildScreenshotIndexJob;
use Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Reads the panel sitemap, fans out one CapturePageScreenshotsJob per
 * page into a Bus::batch, and chains a RebuildScreenshotIndexJob as the
 * `finally` callback. Failure of any single page-capture job is
 * isolated — it doesn't cancel siblings, and the index rebuilder runs
 * regardless so partial results are still publishable.
 *
 * Use this in preference to the synchronous screenshot:capture command
 * for full-panel runs; capture stays for ad-hoc local debugging.
 */
class DispatchScreenshotCaptureCommand extends Command
{
    protected $signature = 'screenshot:dispatch
        {--panel=enduser : The Filament panel key}
        {--page=* : Limit dispatch to specific sitemap slugs (repeatable). Default: every entry.}
        {--mode=* : Mode to capture (light|dark). Default: light + dark.}
        {--viewport=* : Viewport to capture (desktop|tablet|mobile). Default: all three.}
        {--tag=latest : Version segment for the S3 path. "latest" overwrites; pass a tag (e.g. v2.5.0) for an immutable run.}
        {--queue= : Queue connection/name to dispatch onto. Defaults to the app queue.}';

    protected $description = 'Dispatch a Bus::batch of one CapturePageScreenshotsJob per sitemap entry, then rebuild the index.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to dispatch in production (NB-2505 safety guard).');

            return self::FAILURE;
        }

        if (config('app.debug')) {
            $this->error('APP_DEBUG=true would render the dev debug bar in every shot. Set APP_DEBUG=false and re-run.');

            return self::FAILURE;
        }

        $panel = $this->option('panel');
        if (ScreenshotConfig::panelCredentials($panel) === null) {
            $this->error("Unknown panel: {$panel}");

            return self::FAILURE;
        }

        $pages = $this->loadPages($panel, (array) $this->option('page'));
        if (empty($pages)) {
            $this->error('No sitemap entries to dispatch — generate a sitemap first via `panel:sitemap`.');

            return self::FAILURE;
        }

        $viewports = $this->resolveViewportsDefaultAll((array) $this->option('viewport'));
        $modes = $this->resolveModesDefaultBoth((array) $this->option('mode'));
        $env = ScreenshotConfig::resolveEnv();
        $version = $this->option('tag');

        $jobs = collect($pages)
            ->map(fn (array $page) => new CapturePageScreenshotsJob(
                panelKey: $panel,
                page: $page,
                viewports: $viewports,
                modes: $modes,
                env: $env,
                version: $version,
            ))
            ->all();

        $finalize = new RebuildScreenshotIndexJob(
            panelKey: $panel,
            env: $env,
            version: $version,
        );

        $this->info("Dispatching " . count($jobs) . " page jobs (" . count($viewports) . ' viewport(s) × ' . count($modes) . ' mode(s) each)');

        $queueName = (string) config('screenshot-catalogue.queue', 'screenshots');

        $pendingBatch = Bus::batch($jobs)
            ->name("screenshot-capture:{$panel}:{$version}")
            ->onQueue($queueName)
            ->allowFailures()
            // `finally` fires after every job has run, regardless of
            // success/failure — so the index always rebuilds against
            // whatever shots actually landed on S3. If the
            // filament-screenshot-review package is installed, also fan
            // out a sync so its DB-backed Captures grid mirrors S3
            // without requiring a manual `screenshot-review:sync-captures`.
            ->finally(function (Batch $batch) use ($finalize, $panel, $version, $queueName): void {
                dispatch($finalize);

                // Soft hook — if the filament-screenshot-review package
                // is installed, ingest the new captures into its DB so
                // the Captures grid stays in step with S3 without a
                // manual `screenshot-review:sync-captures` call. Falls
                // through silently when the package isn't present.
                if (class_exists(\Visualbuilder\FilamentScreenshotReview\Console\Commands\SyncScreenshotCapturesCommand::class)) {
                    dispatch(function () use ($panel, $version): void {
                        \Illuminate\Support\Facades\Artisan::call('screenshot-review:sync-captures', [
                            '--panel' => $panel,
                            '--tag' => $version,
                        ]);
                    })->onQueue($queueName);
                }
            });

        if ($queue = $this->option('queue')) {
            $pendingBatch->onConnection($queue);
        }

        $batch = $pendingBatch->dispatch();

        $this->newLine();
        $this->info("Batch ID: {$batch->id}");
        $this->line('Monitor:  php artisan queue:work    (or your existing worker)');
        $this->line("Index:    " . $this->indexUrl($panel, $env, $version));

        return self::SUCCESS;
    }

    /**
     * Reads the panel sitemap and filters to the requested slugs.
     *
     * @param  array<int, string>  $filterSlugs
     * @return array<int, array{slug: string, url: string, label: string, auth: string}>
     */
    private function loadPages(string $panelKey, array $filterSlugs): array
    {
        $panel = ScreenshotConfig::panelInternalId($panelKey);
        $path = storage_path("app/sitemap-{$panel}.json");

        if (! file_exists($path)) {
            $this->warn("Sitemap not found at {$path} — run `panel:sitemap --panel={$panel}` first.");

            return [];
        }

        $entries = collect(json_decode((string) file_get_contents($path), true) ?: [])
            ->map(fn (array $entry): array => [
                'slug' => $entry['slug'],
                'url' => $entry['url'],
                'label' => $entry['label'] ?? $entry['slug'],
                'auth' => $entry['auth'] ?? 'authenticated',
            ]);

        if (! empty($filterSlugs)) {
            $entries = $entries->filter(fn (array $p): bool => in_array($p['slug'], $filterSlugs, true));
        }

        return $entries->values()->all();
    }

    /**
     * Default to capturing both light and dark when no --mode is passed,
     * since this is the full-fanout path. (The sync command defaults to
     * just light because it's used for ad-hoc single-page checks.)
     *
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    private function resolveModesDefaultBoth(array $requested): array
    {
        if (empty($requested)) {
            return ['light', 'dark'];
        }

        return ScreenshotConfig::resolveModes($requested);
    }

    /**
     * Default to all three viewports when --viewport is not passed.
     *
     * @param  array<int, string>  $requested
     * @return array<int, array{name: string, width: int, height: int}>
     */
    private function resolveViewportsDefaultAll(array $requested): array
    {
        if (empty($requested)) {
            return array_values(ScreenshotConfig::viewportDefinitions());
        }

        return ScreenshotConfig::resolveViewports($requested);
    }

    private function indexUrl(string $panelKey, string $env, string $version): string
    {
        $panel = ScreenshotConfig::panelInternalId($panelKey);
        $key = ScreenshotConfig::s3Key($env, $panel, $version, '', 'index.html');

        return \Storage::disk(ScreenshotConfig::disk())->url($key);
    }
}
