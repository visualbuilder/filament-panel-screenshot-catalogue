<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentPanelScreenshotCatalogue;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Visualbuilder\FilamentPanelScreenshotCatalogue\Console\Commands\CaptureScreenshotsCommand;
use Visualbuilder\FilamentPanelScreenshotCatalogue\Console\Commands\DispatchScreenshotCaptureCommand;
use Visualbuilder\FilamentPanelScreenshotCatalogue\Console\Commands\GeneratePanelSitemapCommand;
use Visualbuilder\FilamentPanelScreenshotCatalogue\Console\Commands\RebuildScreenshotIndexCommand;

class FilamentPanelScreenshotCatalogueServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-panel-screenshot-catalogue')
            ->hasConfigFile('panel-screenshot-catalogue')
            ->hasViews('filament-panel-screenshot-catalogue')
            ->hasCommands([
                CaptureScreenshotsCommand::class,
                DispatchScreenshotCaptureCommand::class,
                GeneratePanelSitemapCommand::class,
                RebuildScreenshotIndexCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        $this->publishes([
            __DIR__ . '/../resources/js' => base_path('resources/js/screenshot-catalogue'),
        ], 'filament-panel-screenshot-catalogue-js');
    }
}
