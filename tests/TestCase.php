<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Visualbuilder\FilamentScreenshotCatalogue\FilamentScreenshotCatalogueServiceProvider;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;

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
        return [FilamentScreenshotCatalogueServiceProvider::class];
    }
}
