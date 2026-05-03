<?php

declare(strict_types=1);

use Visualbuilder\FilamentScreenshotCatalogue\PanelDescriptor;
use Visualbuilder\FilamentScreenshotCatalogue\PanelRegistry;

function descriptor(string $key = 'enduser', string $panelId = 'endUser'): PanelDescriptor
{
    return new PanelDescriptor(
        key: $key,
        panelId: $panelId,
        domain: 'example.test',
        loginHeading: 'Login',
        email: 'user@example.test',
        password: 'secret',
    );
}

it('returns null for unknown panel keys', function (): void {
    expect(PanelRegistry::get('admin'))->toBeNull();
});

it('resolves a registered descriptor by friendly key', function (): void {
    PanelRegistry::register(descriptor());

    $resolved = PanelRegistry::get('enduser');

    expect($resolved)->not->toBeNull()
        ->and($resolved->panelId)->toBe('endUser');
});

it('resolves the same descriptor by Filament internal panel id', function (): void {
    PanelRegistry::register(descriptor());

    expect(PanelRegistry::get('endUser'))->not->toBeNull()
        ->and(PanelRegistry::get('endUser')->key)->toBe('enduser');
});

it('overwrites a previous descriptor when registering with the same key', function (): void {
    PanelRegistry::register(descriptor(panelId: 'endUser'));
    PanelRegistry::register(descriptor(panelId: 'endUserV2'));

    expect(PanelRegistry::get('enduser')->panelId)->toBe('endUserV2');
});

it('lists every registered key', function (): void {
    PanelRegistry::register(descriptor('enduser', 'endUser'));
    PanelRegistry::register(descriptor('admin', 'admin'));

    expect(PanelRegistry::keys())->toEqualCanonicalizing(['enduser', 'admin']);
});

it('flush() empties the registry', function (): void {
    PanelRegistry::register(descriptor());

    PanelRegistry::flush();

    expect(PanelRegistry::keys())->toBe([])
        ->and(PanelRegistry::get('enduser'))->toBeNull();
});

it('runs the descriptor authenticator closure when invoked', function (): void {
    $called = false;

    PanelRegistry::register(new PanelDescriptor(
        key: 'enduser',
        panelId: 'endUser',
        domain: 'example.test',
        loginHeading: 'Login',
        email: 'user@example.test',
        password: 'secret',
        authenticator: function () use (&$called): void {
            $called = true;
        },
    ));

    ($descriptor = PanelRegistry::get('enduser'))->authenticator?->call($descriptor);

    expect($called)->toBeTrue();
});
