<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by PageCaptureService immediately after each PNG lands on S3
 * — once per (panel, slug, viewport, mode) tuple, not once per page.
 *
 * Optional consumers (e.g. visualbuilder/filament-screenshot-review)
 * listen and write a corresponding screenshot_captures row so the
 * review UI fills incrementally as a batch progresses, instead of
 * waiting for the batch's `finally` callback to fire a full sync at
 * the end.
 *
 * The catalogue itself never reads back from this event — the file is
 * already on S3 by the time we dispatch. Listeners are free to fail
 * without affecting the capture pipeline.
 */
class ScreenshotCaptured
{
    use Dispatchable;

    public function __construct(
        /** Friendly CLI key from PanelDescriptor (e.g. `enduser`) — what's stored in screenshot_pages.panel. */
        public readonly string $panelKey,
        /** Filament's internal panel ID (e.g. `endUser`) — what's used in the S3 path. */
        public readonly string $panelId,
        /** Page slug (e.g. `coaching-sessions.index`). */
        public readonly string $slug,
        /** Viewport name (`desktop`/`tablet`/`mobile`). */
        public readonly string $viewport,
        /** Mode (`light`/`dark`). */
        public readonly string $mode,
        /** Capture env (`dev`/`development`/...). */
        public readonly string $env,
        /** Tag / version segment (`latest`, `fix-NB-2509`, …). */
        public readonly string $version,
        /** Filesystem disk name (e.g. `s3_public`) — the listener uses this to fetch the file if needed. */
        public readonly string $disk,
        /** Key on the disk (e.g. `screenshots/dev/endUser/latest/orders.index/desktop-light.png`). */
        public readonly string $key,
        /** MD5 of the uploaded bytes — listeners use this as the dedupe key. */
        public readonly string $etag,
        /** File size in bytes. */
        public readonly int $size,
        /** Public URL of the upload (convenience — same as `Storage::disk($disk)->url($key)`). */
        public readonly string $url,
    ) {}
}
