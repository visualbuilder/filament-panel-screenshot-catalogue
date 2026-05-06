<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Visualbuilder\FilamentScreenshotCatalogue\Console\Commands\CaptureScreenshotsCommand;
use Visualbuilder\FilamentScreenshotCatalogue\Console\Commands\CaptureUrlScreenshotCommand;
use Visualbuilder\FilamentScreenshotCatalogue\Console\Commands\DispatchScreenshotCaptureCommand;
use Visualbuilder\FilamentScreenshotCatalogue\Console\Commands\FlushScreenshotQueueCommand;
use Visualbuilder\FilamentScreenshotCatalogue\Console\Commands\GeneratePanelSitemapCommand;
use Visualbuilder\FilamentScreenshotCatalogue\Console\Commands\ListIndexesCommand;
use Visualbuilder\FilamentScreenshotCatalogue\Console\Commands\RebuildScreenshotIndexCommand;

class FilamentScreenshotCatalogueServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-screenshot-catalogue')
            ->hasConfigFile('screenshot-catalogue')
            ->hasViews('filament-screenshot-catalogue')
            ->hasCommands([
                CaptureScreenshotsCommand::class,
                CaptureUrlScreenshotCommand::class,
                DispatchScreenshotCaptureCommand::class,
                FlushScreenshotQueueCommand::class,
                GeneratePanelSitemapCommand::class,
                ListIndexesCommand::class,
                RebuildScreenshotIndexCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__ . '/../resources/js' => base_path('resources/js/screenshot-catalogue'),
        ], 'filament-screenshot-catalogue-js');

        // Claude Code slash command — opt-in install for hosts that use
        // Claude Code to drive the catalogue. Copies into the host's own
        // `.claude/commands/`, where the slash command becomes invokable.
        $this->publishes([
            __DIR__ . '/../.claude/commands' => base_path('.claude/commands'),
        ], 'filament-screenshot-catalogue-claude-skills');
    }
}
