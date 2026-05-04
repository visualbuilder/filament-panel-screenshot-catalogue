<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Walks the configured S3 disk and lists every per-panel index.html
 * the catalogue has produced. Used by both `screenshot:list-indexes`
 * (CLI enumeration) and MetaIndexBuilderService (the top-level
 * meta-index that links to each panel's catalogue).
 *
 * The catalogue lays out S3 as:
 *   {prefix}/{env}/{panel}/{version}/index.html
 *   {prefix}/{env}/{panel}/{version}/{slug}/{viewport}-{mode}.png
 * (production omits `{env}` per ScreenshotConfig::s3Key()).
 *
 * Filter inputs are optional — pass nulls / empty strings to skip
 * the corresponding filter.
 */
class IndexEnumerator
{
    /**
     * @param  string|null  $env       e.g. 'development', null = all envs
     * @param  string|null  $panel     e.g. 'admin', null = all panels
     * @param  string|null  $version   e.g. 'latest', null = all versions
     * @return array<int, array{env: string, panel: string, version: string, key: string, url: string, last_modified: \Illuminate\Support\Carbon, size: int, capture_count: int}>
     */
    public function enumerate(?string $env = null, ?string $panel = null, ?string $version = null): array
    {
        $disk = Storage::disk(ScreenshotConfig::disk());
        $prefix = (string) config('screenshot-catalogue.path_prefix', 'screenshots');

        $files = $disk->allFiles($prefix);

        $results = [];
        foreach ($files as $key) {
            if (! str_ends_with($key, '/index.html')) {
                continue;
            }

            $parsed = $this->parseKey($key, $prefix);
            if ($parsed === null) {
                continue;
            }

            if ($env !== null && $env !== '' && $parsed['env'] !== $env) {
                continue;
            }
            if ($panel !== null && $panel !== '' && $parsed['panel'] !== $panel) {
                continue;
            }
            if ($version !== null && $version !== '' && $parsed['version'] !== $version) {
                continue;
            }

            $results[] = [
                'env' => $parsed['env'],
                'panel' => $parsed['panel'],
                'version' => $parsed['version'],
                'key' => $key,
                'url' => $disk->url($key),
                'last_modified' => Carbon::createFromTimestamp($disk->lastModified($key)),
                'size' => $disk->size($key),
                // Cheap proxy for "captures behind this index" — count
                // PNGs at the panel's prefix. Avoids a recursive listFiles()
                // since allFiles already walked the tree once.
                'capture_count' => $this->countPngsForPanel($files, $key),
            ];
        }

        usort($results, function ($a, $b) {
            return [$a['env'], $a['panel'], $a['version']]
                <=> [$b['env'], $b['panel'], $b['version']];
        });

        return $results;
    }

    /**
     * Parse a key of shape:
     *   {prefix}/{env}/{panel}/{version}/index.html       (non-prod)
     *   {prefix}/{panel}/{version}/index.html             (production — env omitted)
     *
     * Returns null for keys outside the catalogue's layout (e.g. brand
     * favicon at `{prefix}/{env}/{panel}/{version}/favicon.svg`).
     *
     * @return array{env: string, panel: string, version: string}|null
     */
    private function parseKey(string $key, string $prefix): ?array
    {
        $relative = ltrim(substr($key, strlen($prefix)), '/');
        $parts = explode('/', $relative);

        // {env}/{panel}/{version}/index.html → 4 parts, last = 'index.html'
        if (count($parts) === 4 && $parts[3] === 'index.html') {
            return ['env' => $parts[0], 'panel' => $parts[1], 'version' => $parts[2]];
        }

        // {panel}/{version}/index.html (production layout) → 3 parts
        if (count($parts) === 3 && $parts[2] === 'index.html') {
            return ['env' => 'production', 'panel' => $parts[0], 'version' => $parts[1]];
        }

        return null;
    }

    /**
     * Count PNGs nested under the same panel/version prefix as a given
     * index.html key. Used as a "fresh captures behind this index"
     * indicator on the meta-index page.
     *
     * @param  array<int, string>  $allFiles
     */
    private function countPngsForPanel(array $allFiles, string $indexKey): int
    {
        $prefix = substr($indexKey, 0, -strlen('index.html'));

        return count(array_filter(
            $allFiles,
            static fn (string $f): bool => str_starts_with($f, $prefix) && str_ends_with($f, '.png'),
        ));
    }
}
