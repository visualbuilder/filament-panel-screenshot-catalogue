<?php

declare(strict_types=1);

namespace Visualbuilder\FilamentScreenshotCatalogue\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Visualbuilder\FilamentScreenshotCatalogue\Services\ScreenshotConfig;

/**
 * Single-URL screenshot capture. Hits one URL, optionally logging in
 * first via a generic form (selectors default to WordPress wp-login.php
 * but can be overridden), and writes one PNG to disk. No S3 upload, no
 * panel registry — this is the ad-hoc cousin of `screenshot:capture`,
 * meant for grabbing a shot of a WordPress admin page or any other
 * stand-alone URL.
 */
class CaptureUrlScreenshotCommand extends Command
{
    protected $signature = 'screenshot:url
        {--url= : The full URL to capture (required).}
        {--output= : Path to write the PNG. Default: storage/app/screenshots/single/<timestamp>.png}
        {--login-url= : Login page URL. Omit to skip login.}
        {--username= : Login username/email.}
        {--password= : Login password.}
        {--username-selector=#user_login : CSS selector for the username field. Default targets WordPress wp-login.php.}
        {--password-selector=#user_pass : CSS selector for the password field.}
        {--submit-selector=#wp-submit : CSS selector for the submit button.}
        {--viewport=desktop : Viewport: desktop (1280×800), tablet (768×1024), mobile (375×812), or "custom" with --width/--height. Names from screenshot-catalogue.viewports config.}
        {--width= : Viewport width in pixels (used when --viewport=custom).}
        {--height= : Viewport height in pixels (used when --viewport=custom).}
        {--full-page : Capture the full scrollable page rather than just the viewport.}
        {--wait=1500 : Milliseconds to wait after navigation before the screenshot fires.}
        {--ignore-https-errors : Ignore TLS errors (useful for local self-signed certs).}';

    protected $description = 'Capture a single URL to a PNG, optionally logging in first via a generic HTML login form.';

    public function handle(): int
    {
        $url = (string) $this->option('url');
        if ($url === '') {
            $this->error('The --url option is required.');

            return self::FAILURE;
        }

        $loginUrl = (string) $this->option('login-url');
        $username = (string) $this->option('username');
        $password = (string) $this->option('password');
        $hasCredentials = $username !== '' && $password !== '';

        if ($loginUrl !== '' && ! $hasCredentials) {
            $this->error('--login-url was provided but --username/--password are missing.');

            return self::FAILURE;
        }
        if ($hasCredentials && $loginUrl === '') {
            $this->error('--username/--password were provided but --login-url is missing.');

            return self::FAILURE;
        }

        $viewport = $this->resolveViewport();
        if ($viewport === null) {
            return self::FAILURE;
        }

        $output = (string) $this->option('output');
        if ($output === '') {
            $output = storage_path('app/screenshots/single/' . now()->format('Ymd-His') . '.png');
        }

        $manifest = [
            'url' => $url,
            'output' => $output,
            'viewport' => $viewport,
            'fullPage' => (bool) $this->option('full-page'),
            'waitMs' => (int) $this->option('wait'),
            'ignoreHttpsErrors' => (bool) $this->option('ignore-https-errors'),
            'login' => $hasCredentials ? [
                'url' => $loginUrl,
                'username' => $username,
                'password' => $password,
                'usernameSelector' => (string) $this->option('username-selector'),
                'passwordSelector' => (string) $this->option('password-selector'),
                'submitSelector' => (string) $this->option('submit-selector'),
            ] : null,
        ];

        $this->info("Capturing {$url}");
        $this->line("Viewport: {$viewport['name']} ({$viewport['width']}×{$viewport['height']})"
            . ($manifest['fullPage'] ? ' full-page' : ''));
        if ($hasCredentials) {
            $this->line("Login via {$loginUrl} as {$username}");
        }

        $script = ScreenshotConfig::captureUrlScript();
        $process = new Process(['node', $script], base_path(), null, json_encode($manifest), 120);
        $process->mustRun(function ($type, $buffer): void {
            $this->getOutput()->write($buffer);
        });

        $this->newLine();
        $this->info("Saved → {$output}");

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, width: int, height: int}|null
     */
    private function resolveViewport(): ?array
    {
        $name = strtolower((string) $this->option('viewport'));

        if ($name === 'custom') {
            $width = (int) $this->option('width');
            $height = (int) $this->option('height');
            if ($width <= 0 || $height <= 0) {
                $this->error('--viewport=custom requires --width and --height.');

                return null;
            }

            return ['name' => 'custom', 'width' => $width, 'height' => $height];
        }

        $defs = ScreenshotConfig::viewportDefinitions();
        if (! isset($defs[$name])) {
            $supported = implode(', ', array_keys($defs));
            $this->error("Unknown viewport: {$name}. Supported: {$supported} (or 'custom' with --width/--height).");

            return null;
        }

        return $defs[$name];
    }
}
