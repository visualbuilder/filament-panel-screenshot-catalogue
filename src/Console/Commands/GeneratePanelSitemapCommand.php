<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;

/**
 * Walks a Filament panel and emits a flat list of every navigable URL —
 * resource indexes, custom pages, and edit/view pages with concrete
 * sample record IDs filled in. Saved to
 * `storage/app/sitemap-{panel}.json` for any tool that wants to iterate
 * every page (screenshot catalogue, accessibility audit, future visual
 * regression diffs, etc.).
 *
 * Authentication context is delegated to the panel's registered
 * `PanelDescriptor::$authenticator` closure, so each host wires its own
 * model lookups + tenancy without the package needing to know about them.
 */
class GeneratePanelSitemapCommand extends Command
{
    protected $signature = 'panel:sitemap
        {--panel=enduser : The Filament panel ID}
        {--out= : Override the output path. Defaults to storage/app/sitemap-{panel}.json}';

    protected $description = 'Generate a JSON sitemap of every page in a Filament panel.';

    /**
     * Slugs to omit from the sitemap come from
     * `screenshot-catalogue.excluded_slugs` so each host can hide
     * pages that aren't useful in the catalogue (empty without seed
     * fixtures, deprecated UI, etc.) without forking the package.
     *
     * @return array<int, string>
     */
    private function excludedSlugs(): array
    {
        $configured = config('screenshot-catalogue.excluded_slugs', []);

        return is_array($configured) ? $configured : [];
    }

    public function handle(): int
    {
        // Translate the friendly CLI key (e.g. `enduser`) to Filament's
        // internal panel ID (`endUser`). Falls through unchanged when no
        // descriptor is registered, preserving the old behaviour.
        $panelId = \Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig::panelInternalId(
            $this->option('panel')
        );
        $panel = Filament::getPanel($panelId, isStrict: false);

        if ($panel === null) {
            $this->error("Unknown panel: {$panelId}");

            return self::FAILURE;
        }

        // Setting the current panel makes Filament's URL helpers resolve
        // routes against this panel's domain/path instead of the default.
        Filament::setCurrentPanel($panel);

        $this->authForPanel($panelId);

        $entries = [
            ...$this->authEntries($panel, $panelId),
            ...$this->resourceEntries($panel, $panelId),
            ...$this->customPageEntries($panel, $panelId),
            ...$this->extraEntries($panelId),
        ];

        $excluded = $this->excludedSlugs();
        $entries = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => ! in_array($entry['slug'], $excluded, true),
        ));

        // Order: auth pages first, then dashboard, then resources by their
        // navigationSort (with index/create/view/edit grouped per resource),
        // then other custom pages last. Mirrors a sensible top-down read of
        // the panel rather than the discovery order.
        usort($entries, function (array $a, array $b): int {
            return [$a['sort'], $a['slug']] <=> [$b['sort'], $b['slug']];
        });

        $output = $this->option('out') ?: storage_path("app/sitemap-{$panelId}.json");
        file_put_contents($output, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info(count($entries) . " entries → {$output}");

        return self::SUCCESS;
    }

    /**
     * Authenticate as the registered sample user for the panel so
     * policy-gated URL helpers and global scopes resolve to a consistent
     * record set. Hosts attach an `authenticator` closure to their
     * `PanelDescriptor` — the package just invokes it.
     */
    private function authForPanel(string $panelId): void
    {
        $descriptor = PanelRegistry::get($panelId);
        if ($descriptor?->authenticator !== null) {
            ($descriptor->authenticator)();
        }
    }

    /**
     * @return array<int, array{slug: string, url: string, type: string, label: string, sort: int}>
     */
    private function resourceEntries(\Filament\Panel $panel, string $panelId): array
    {
        $entries = [];

        foreach ($panel->getResources() as $resourceClass) {
            // One bad resource shouldn't tank the whole panel's sitemap.
            // Real-world hosts often have a resource that calls
            // `getPlugin('foo')` against a panel where `foo` isn't
            // registered, or otherwise blows up at metadata-load time.
            // Skip with a warning, keep going.
            try {
                $entries = array_merge(
                    $entries,
                    $this->resourcePageEntries($resourceClass, $panelId),
                );
            } catch (\Throwable $e) {
                $this->warn(sprintf(
                    '  ! Skipping resource %s on panel %s: %s',
                    class_basename($resourceClass),
                    $panelId,
                    $e->getMessage(),
                ));
            }
        }

        return $entries;
    }

    /**
     * Build sitemap entries for every navigable page on a single resource.
     * Extracted so resourceEntries() can wrap each call in a try/catch
     * without nesting deeply.
     *
     * @return array<int, array<string, mixed>>
     */
    private function resourcePageEntries(string $resourceClass, string $panelId): array
    {
        $entries = [];
        $resourceSlug = $resourceClass::getSlug();
        $baseLabel = $this->resourceLabel($resourceClass);
        // Resources slot into the 100-8999 range so they sit after
        // auth + dashboard but before generic custom pages. The
        // Filament navigationSort is a small int; multiply by 10 so
        // we still have room for index/create/view/edit sub-ordering.
        $resourceSort = 100 + (((int) ($resourceClass::getNavigationSort() ?? 100)) * 10);

        foreach (array_keys($resourceClass::getPages()) as $pageName) {
            $type = match ($pageName) {
                'index' => 'resource_index',
                'create' => 'resource_create',
                'view' => 'resource_view',
                'edit' => 'resource_edit',
                default => 'resource_' . $pageName,
            };

            $label = match ($pageName) {
                'index' => $baseLabel,
                'create' => "{$baseLabel} · Create",
                'view' => "{$baseLabel} · View",
                'edit' => "{$baseLabel} · Edit",
                default => "{$baseLabel} · " . ucfirst($pageName),
            };

            // Sub-order pages within a resource: index → create → view → edit.
            $sort = $resourceSort + match ($pageName) {
                'index' => 0,
                'create' => 1,
                'view' => 2,
                'edit' => 3,
                default => 4,
            };

            // Try the page without a record first. If the route URI
            // declares {record} (e.g. view, edit, or any custom page
            // like ".../{record}/revisions"), Laravel throws
            // UrlGenerationException. We catch and retry with a sample
            // record. Avoids hardcoding the page-name list, which
            // misses every custom resource page.
            try {
                $url = $resourceClass::getUrl($pageName, isAbsolute: false, panel: $panelId);
                $entries[] = $this->makeEntry(
                    slug: $resourceSlug . '.' . $pageName,
                    url: $url,
                    type: $type,
                    label: $label,
                    sort: $sort,
                    extra: ['resource' => $resourceClass],
                );
            } catch (\Illuminate\Routing\Exceptions\UrlGenerationException) {
                $record = $this->findSampleRecord($resourceClass, $panelId);
                if ($record === null) {
                    continue;
                }

                try {
                    $url = $resourceClass::getUrl(
                        $pageName,
                        ['record' => $record],
                        isAbsolute: false,
                        panel: $panelId,
                    );
                } catch (\Throwable) {
                    // Page needs more than just `record` — give up on it
                    // rather than crash the whole sitemap.
                    continue;
                }

                $entries[] = $this->makeEntry(
                    slug: $resourceSlug . '.' . $pageName,
                    url: $url,
                    type: $type,
                    label: $label,
                    sort: $sort,
                    extra: [
                        'resource' => $resourceClass,
                        'record_id' => $record->getKey(),
                    ],
                );
            }
        }

        return $entries;
    }

    private function resourceLabel(string $resourceClass): string
    {
        return $resourceClass::getNavigationLabel()
            ?: $resourceClass::getModelLabel()
            ?: class_basename($resourceClass);
    }

    /**
     * @return array<int, array{slug: string, url: string, type: string, label: string, sort: int}>
     */
    private function customPageEntries(\Filament\Panel $panel, string $panelId): array
    {
        $entries = [];

        foreach ($panel->getPages() as $pageClass) {
            try {
                $url = $pageClass::getUrl(isAbsolute: false, panel: $panelId);
            } catch (\Throwable) {
                continue;
            }

            $isDashboard = is_subclass_of($pageClass, \Filament\Pages\Dashboard::class);
            $sort = $isDashboard
                // Dashboard always first.
                ? 1
                // Other custom pages slot in after resources but before auth (9000-9499).
                : 9000 + (method_exists($pageClass, 'getNavigationSort')
                    ? (int) ($pageClass::getNavigationSort() ?? 0)
                    : 0);

            $entries[] = $this->makeEntry(
                slug: $this->classToSlug($pageClass),
                url: $url,
                type: 'page',
                label: $this->pageLabel($pageClass),
                sort: $sort,
                extra: ['class' => $pageClass],
            );
        }

        return $entries;
    }

    /**
     * @return array<int, array{slug: string, url: string, type: string, label: string, auth: string, sort: int}>
     */
    private function authEntries(\Filament\Panel $panel, string $panelId): array
    {
        $entries = [];

        $loginUrl = $panel->getLoginUrl();
        if ($loginUrl) {
            $entries[] = $this->makeEntry(
                slug: 'auth.login',
                url: parse_url($loginUrl, PHP_URL_PATH) ?: '/login',
                type: 'auth_login',
                label: 'Login',
                sort: 9500,
                extra: ['auth' => 'unauthenticated'],
            );
        }

        if (method_exists($panel, 'getRequestPasswordResetUrl')
            && ($resetUrl = $panel->getRequestPasswordResetUrl())) {
            $entries[] = $this->makeEntry(
                slug: 'auth.password-reset',
                url: parse_url($resetUrl, PHP_URL_PATH) ?: '/password-reset/request',
                type: 'auth_password_reset',
                label: 'Password reset',
                sort: 9501,
                extra: ['auth' => 'unauthenticated'],
            );
        }

        return $entries;
    }

    /**
     * Hard-coded URLs that aren't discoverable via Filament's resource/page
     * registries — typically pages provided by plugins whose Page class
     * doesn't implement `getUrl()` (e.g. the 2FA Configure page from
     * visualbuilder/filament-2fa).
     *
     * @return array<int, array{slug: string, url: string, type: string, label: string, sort: int}>
     */
    private function extraEntries(string $panelId): array
    {
        $entries = [];

        if (\Illuminate\Support\Facades\Route::has("filament.{$panelId}.two-factor-authentication")) {
            $entries[] = $this->makeEntry(
                slug: 'auth.two-factor',
                url: '/two-factor-authentication',
                type: 'page',
                label: 'Two-Factor Authentication',
                sort: 9050, // alongside Change Password / account-management pages
            );
        }

        return $entries;
    }

    private function pageLabel(string $pageClass): string
    {
        // Prefer the page's navigation label (static), falling back to
        // a humanised class basename. getTitle() is instance-only so we
        // don't try it from a static context.
        if (method_exists($pageClass, 'getNavigationLabel')) {
            $label = $pageClass::getNavigationLabel();
            if (filled($label)) {
                return $label;
            }
        }

        return preg_replace('/(?<!^)([A-Z])/', ' $1', class_basename($pageClass));
    }

    /**
     * Pick a representative record for an edit/view page. Prefers a
     * record owned by the panel's currently-authenticated sample user
     * (set by the descriptor's `authenticator` closure earlier) so URLs
     * land on data the catalogue expects to render.
     *
     * Ownership detection — in order:
     *   1. Direct foreign key derived from the user's class basename
     *      (EndUser → `end_user_id`, OrganisationUser → `organisation_user_id`).
     *   2. `user_id` / `owner_id` if either of those columns exist.
     *   3. Polymorphic ownership: any `*_type` / `*_id` column pair where
     *      the type matches the authenticated user's class.
     *   4. First-match fallback so the catalogue still has *something*
     *      to point at.
     */
    private function findSampleRecord(string $resourceClass, string $panelId): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $resourceClass::getModel();
        $instance = new $modelClass;
        $table = $instance->getTable();

        $query = $modelClass::query();

        $authUser = $this->resolveAuthUser($panelId);
        if ($authUser === null) {
            return $query->first();
        }

        $userClass = $authUser::class;
        $userId = $authUser->getKey();

        // Direct ownership: derive likely FK column name from the user's class.
        $derivedFk = \Illuminate\Support\Str::snake(class_basename($userClass)) . '_id';

        foreach (array_unique([$derivedFk, 'user_id', 'owner_id']) as $column) {
            if (Schema::hasColumn($table, $column)) {
                $candidate = (clone $query)->where("{$table}.{$column}", $userId)->first();
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        // Polymorphic ownership: any `*_type` / `*_id` pair where the
        // type column matches the auth user's class.
        foreach (Schema::getColumnListing($table) as $column) {
            if (! str_ends_with($column, '_type')) {
                continue;
            }
            $idColumn = substr($column, 0, -5) . '_id';
            if (! Schema::hasColumn($table, $idColumn)) {
                continue;
            }

            $candidate = (clone $query)
                ->where("{$table}.{$column}", $userClass)
                ->where("{$table}.{$idColumn}", $userId)
                ->first();
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $query->first();
    }

    /**
     * Resolve the authenticated sample user for the panel. The
     * `authenticator` closure registered on the panel's descriptor has
     * already run by this point, so we just need the right guard.
     */
    private function resolveAuthUser(string $panelId): ?Model
    {
        $panel = Filament::getPanel($panelId, isStrict: false);
        $guard = $panel?->getAuthGuard();

        return $guard ? auth($guard)->user() : null;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function makeEntry(string $slug, string $url, string $type, string $label, int $sort, array $extra = []): array
    {
        return [
            'slug' => $slug,
            'label' => $label,
            'url' => $url,
            'type' => $type,
            'sort' => $sort,
            ...$extra,
        ];
    }

    private function classToSlug(string $class): string
    {
        $short = class_basename($class);

        return strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $short));
    }
}
