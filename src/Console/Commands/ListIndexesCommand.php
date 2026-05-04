<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Illuminate\Console\Command;
use Visualbuilder\FilamentScreenshotCatalogue\Services\IndexEnumerator;

/**
 * Lists every panel index.html produced by the catalogue, with their
 * public S3 URLs, last-rebuilt timestamps, and capture counts. Useful
 * for "where are all the screenshots?" enumeration without needing to
 * remember per-panel URLs.
 *
 * Filter via --env / --panel / --tag flags. Defaults to a human-
 * readable table; pass --json for scripting.
 */
class ListIndexesCommand extends Command
{
    protected $signature = 'screenshot:list-indexes
        {--env= : Filter by environment (e.g. development). Empty = all envs.}
        {--panel= : Filter by panel key (e.g. admin). Empty = all panels.}
        {--tag= : Filter by tag/version (e.g. latest). Empty = all tags.}
        {--json : Emit JSON instead of a formatted table.}';

    protected $description = 'List all panel catalogue index.html files on the configured S3 disk.';

    public function handle(IndexEnumerator $enumerator): int
    {
        $rows = $enumerator->enumerate(
            env: (string) $this->option('env') ?: null,
            panel: (string) $this->option('panel') ?: null,
            version: (string) $this->option('tag') ?: null,
        );

        if ($this->option('json')) {
            $this->line(json_encode(
                array_map(static fn (array $r): array => array_merge($r, [
                    'last_modified' => $r['last_modified']->toIso8601String(),
                ]), $rows),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->warn('No catalogue index.html files found on the configured disk.');

            return self::SUCCESS;
        }

        $this->table(
            ['env', 'panel', 'tag', 'captures', 'updated', 'url'],
            array_map(static fn (array $r): array => [
                $r['env'],
                $r['panel'],
                $r['version'],
                $r['capture_count'],
                $r['last_modified']->diffForHumans(),
                $r['url'],
            ], $rows),
        );

        $this->info(count($rows) . ' index(es) found.');

        return self::SUCCESS;
    }
}
