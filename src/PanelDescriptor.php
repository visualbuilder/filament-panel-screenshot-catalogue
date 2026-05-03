<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue;

use Closure;
use Illuminate\Support\Str;

/**
 * Value object describing one Filament panel for the catalogue.
 *
 * Hosts register one of these per panel they want captured. The package
 * uses the descriptor for two things:
 *
 *   1. Wire-level capture — `domain`, `loginHeading`, `email`, `password`
 *      are passed to the Playwright runner so it can navigate to the
 *      panel and authenticate as a sample user.
 *
 *   2. Sitemap-time auth — `authenticator` is an optional closure called
 *      while generating the sitemap. Use it to set the authenticated user
 *      on the right guard (and, for tenanted panels, the active tenant)
 *      so policy-gated URL helpers and `findSampleRecord` see consistent
 *      records.
 */
final class PanelDescriptor
{
    public function __construct(
        /** Friendly key for CLI use, e.g. `enduser`. Lowercase, no spaces. */
        public readonly string $key,
        /** Filament's internal panel ID, e.g. `endUser`. Often differs from $key. */
        public readonly string $panelId,
        /** Hostname Playwright will navigate to, no scheme. */
        public readonly string $domain,
        /** Heading text shown on the login page (used for early-failure detection). */
        public readonly string $loginHeading,
        /** Sample user's email — must exist as a real user in the panel's auth guard. */
        public readonly string $email,
        /** Plaintext password for the sample user. Pulled from env in practice. */
        public readonly string $password,
        /**
         * Optional closure called during sitemap generation. Use it to
         * `auth($guard)->setUser($user)` and, for tenanted panels,
         * `Filament::setTenant(...)`.
         *
         * @var Closure|null
         */
        public readonly ?Closure $authenticator = null,
        /**
         * Display label for the panel — used by review-side tab labels,
         * dashboards, etc. Falls back to a headlined version of $key
         * (e.g. "enduser" -> "Enduser"), which is fine for single-word
         * keys but not for composites like "customorg" -> set this
         * explicitly to "Custom Organisation".
         */
        public readonly ?string $label = null,
    ) {}

    /**
     * Resolved display label — explicit `$label` if set, otherwise a
     * headlined version of the key.
     */
    public function label(): string
    {
        return $this->label ?? Str::headline($this->key);
    }
}
