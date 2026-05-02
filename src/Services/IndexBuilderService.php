<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentPanelScreenshotCatalogue\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Renders + uploads the run's index.html from whatever shots actually
 * landed on S3. Stateless — listing the bucket prefix is the source of
 * truth, so the index reflects only the captures that succeeded
 * regardless of which jobs failed or timed out.
 */
class IndexBuilderService
{
    /**
     * @return string  The public URL of the uploaded index.
     */
    public function rebuild(string $panelKey, string $env, string $version): string
    {
        $panel = ScreenshotConfig::panelInternalId($panelKey);
        $disk = Storage::disk(ScreenshotConfig::disk());

        $prefix = ScreenshotConfig::s3KeyPrefix($env, $panel, $version);
        $files = $disk->allFiles($prefix);

        $shots = $this->shotsFromS3Listing($files, $disk, $prefix);

        $sitemap = $this->loadSitemap($panel);
        $labelsBySlug = collect($sitemap)->pluck('label', 'slug')->all();

        // Order pages in sitemap order so the index is deterministic
        // regardless of the order S3 listed them. Pages not in the
        // sitemap fall through alphabetically afterwards.
        $sitemapOrder = collect($sitemap)->pluck('slug')->values()->all();
        $shotsBySlug = $shots->groupBy('slug')->sortBy(
            fn ($_, string $slug) => array_search($slug, $sitemapOrder, true) === false
                ? PHP_INT_MAX
                : array_search($slug, $sitemapOrder, true)
        );

        $viewports = ScreenshotConfig::viewportDefinitions();
        $modes = ['light', 'dark'];

        $html = view('filament-panel-screenshot-catalogue::index', [
            'env' => $env,
            'panel' => $panel,
            'version' => $version,
            'capturedAt' => Carbon::now(),
            'shotsBySlug' => $shotsBySlug,
            'viewports' => array_values($viewports),
            'modes' => $modes,
            'labelsBySlug' => $labelsBySlug,
            'brandName' => (string) config('panel-screenshot-catalogue.brand.name', 'Panel Screenshots'),
        ])->render();

        $key = ScreenshotConfig::s3Key($env, $panel, $version, '', 'index.html');
        $disk->put($key, $html, ['ContentType' => 'text/html; charset=utf-8']);

        $this->uploadBrandAsset($disk, $env, $panel, $version, 'favicon', 'favicon.svg');
        $this->uploadBrandAsset($disk, $env, $panel, $version, 'logo', 'logo.svg');

        return $disk->url($key);
    }

    /**
     * Upload an optional brand asset (favicon, logo) next to the index so
     * the rendered page picks them up via relative URL. Source paths come
     * from `panel-screenshot-catalogue.brand.{key}` — leave the config null
     * to skip; the index Blade tolerates a missing image.
     */
    private function uploadBrandAsset(
        \Illuminate\Contracts\Filesystem\Filesystem $disk,
        string $env,
        string $panel,
        string $version,
        string $configKey,
        string $remoteFilename,
    ): void {
        $sourcePath = config("panel-screenshot-catalogue.brand.{$configKey}");
        if (! is_string($sourcePath) || $sourcePath === '' || ! is_file($sourcePath)) {
            return;
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        $disk->put(
            ScreenshotConfig::s3Key($env, $panel, $version, '', $remoteFilename),
            (string) file_get_contents($sourcePath),
            ['ContentType' => $contentType],
        );
    }

    /**
     * Pull shots out of an S3 prefix listing by parsing each key's
     * filename. We expect `…/{slug}/{viewport}-{mode}.png` so anything
     * not matching that shape (e.g. index.html) is skipped.
     *
     * @param  array<int, string>  $files
     */
    private function shotsFromS3Listing(array $files, $disk, string $prefix): \Illuminate\Support\Collection
    {
        return collect($files)
            ->map(function (string $key) use ($disk, $prefix): ?array {
                $relative = ltrim(substr($key, strlen(rtrim($prefix, '/')) + 1), '/');
                $segments = explode('/', $relative);

                // Expected: {slug}/{viewport}-{mode}.png. So the relative
                // path has exactly two segments.
                if (count($segments) !== 2) {
                    return null;
                }

                $slug = $segments[0];
                $file = $segments[1];

                if (! preg_match('/^(desktop|tablet|mobile)-(light|dark)\.png$/', $file, $matches)) {
                    return null;
                }

                return [
                    'slug' => $slug,
                    'viewport' => $matches[1],
                    'mode' => $matches[2],
                    'url' => $disk->url($key),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array<int, array{slug: string, label: string}>
     */
    private function loadSitemap(string $panel): array
    {
        $path = storage_path("app/sitemap-{$panel}.json");
        if (! file_exists($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true) ?: [];
    }
}
