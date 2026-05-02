<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('refuses to run in production', function (): void {
    app()->detectEnvironment(static fn () => 'production');

    expect(Artisan::call('screenshot:capture', ['--panel' => 'enduser']))
        ->toBe(1);

    expect(Artisan::output())->toContain('Refusing to run in production');
});

it('refuses to run with APP_DEBUG enabled', function (): void {
    app()->detectEnvironment(static fn () => 'local');
    config(['app.debug' => true]);

    expect(Artisan::call('screenshot:capture', ['--panel' => 'enduser']))
        ->toBe(1);

    expect(Artisan::output())->toContain('APP_DEBUG=true');
});

it('returns FAILURE when the panel is not registered', function (): void {
    app()->detectEnvironment(static fn () => 'local');
    config(['app.debug' => false]);

    expect(Artisan::call('screenshot:capture', ['--panel' => 'mystery']))
        ->toBe(1);

    expect(Artisan::output())->toContain('Unknown panel: mystery');
});
