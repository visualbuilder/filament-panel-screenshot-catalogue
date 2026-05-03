<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Visualbuilder\FilamentScreenshotCatalogue\Jobs\RebuildScreenshotIndexJob;
use Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

/**
 * Rebuilds the static index.html for an existing screenshot run by listing
 * the run's S3 prefix and re-rendering the Blade template against whatever
 * PNGs are present. Use after editing resources/views/screenshots/* to push
 * the new layout without re-capturing every shot.
 */
class RebuildScreenshotIndexCommand extends Command
{
    protected $signature = 'screenshot:rebuild-index
        {--panel=enduser : The Filament panel key (enduser, etc.)}
        {--tag=latest : Version segment of the S3 path to rebuild}';

    protected $description = 'Re-render index.html for an existing screenshot run without re-capturing the shots.';

    public function handle(): int
    {
        $panelKey = ScreenshotConfig::panelInternalId($this->option('panel'));
        $env = ScreenshotConfig::resolveEnv();
        $version = $this->option('tag');

        $this->info("Rebuilding index for {$panelKey} · env={$env} · tag={$version}");

        Bus::dispatchSync(new RebuildScreenshotIndexJob(
            panelKey: $panelKey,
            env: $env,
            version: $version,
        ));

        $this->info('Done.');

        return self::SUCCESS;
    }
}
