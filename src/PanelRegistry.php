<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentPanelScreenshotCatalogue;

/**
 * In-memory registry of `PanelDescriptor`s. The host registers descriptors
 * once at boot — typically inside `AppServiceProvider::boot()` or a
 * dedicated service provider — and the package's commands look them up
 * by key (lowercase friendly name) or panel ID (Filament's camelCase id).
 *
 * Static-state registry rather than an injected singleton because the
 * package's commands and jobs are constructed by Laravel's container
 * from many entry points (HTTP, queue worker, scheduler) and the registry
 * needs to be available everywhere with zero ceremony.
 */
class PanelRegistry
{
    /**
     * @var array<string, PanelDescriptor>
     */
    private static array $descriptors = [];

    public static function register(PanelDescriptor $descriptor): void
    {
        self::$descriptors[$descriptor->key] = $descriptor;
    }

    /**
     * Resolve a descriptor by its CLI-friendly key (e.g. `enduser`) or
     * Filament's internal panel ID (e.g. `endUser`). Returns null if the
     * panel hasn't been registered.
     */
    public static function get(string $keyOrPanelId): ?PanelDescriptor
    {
        if (isset(self::$descriptors[$keyOrPanelId])) {
            return self::$descriptors[$keyOrPanelId];
        }

        foreach (self::$descriptors as $descriptor) {
            if ($descriptor->panelId === $keyOrPanelId) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * @return array<int, string> Friendly keys of every registered panel.
     */
    public static function keys(): array
    {
        return array_keys(self::$descriptors);
    }

    /**
     * @return array<string, PanelDescriptor>
     */
    public static function all(): array
    {
        return self::$descriptors;
    }

    /**
     * Drop every registered descriptor — used by tests and never in
     * production code paths.
     */
    public static function flush(): void
    {
        self::$descriptors = [];
    }
}
