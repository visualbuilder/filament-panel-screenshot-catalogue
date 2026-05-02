<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentPanelScreenshotCatalogue\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Visualbuilder\FilamentPanelScreenshotCatalogue\FilamentPanelScreenshotCatalogueServiceProvider;
use Visualbuilder\FilamentPanelScreenshotCatalogue\PanelRegistry;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Static registry persists across tests in the same process — flush
        // before each test so register/lookup behaviour is deterministic.
        PanelRegistry::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [FilamentPanelScreenshotCatalogueServiceProvider::class];
    }
}
