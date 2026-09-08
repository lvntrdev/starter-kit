<?php

use Dedoc\Scramble\Scramble;
use LvntR\ApiDock\ApiDockServiceProvider;
use Lvntr\StarterKit\Support\Scramble\ApiResponseExtension;
use Lvntr\StarterKit\Tests\Feature\ApiDock\ApiDockScrambleBridgeTestCase;

uses(ApiDockScrambleBridgeTestCase::class);

function skRegisteredUris(): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route) => $route->uri())
        ->all();
}

it('replaces scramble default doc routes with the api-dock surface', function () {
    $uris = skRegisteredUris();

    expect($uris)->not->toContain('docs/api')
        ->and($uris)->not->toContain('docs/api.json')
        ->and($uris)->toContain('api-dock')
        ->and($uris)->toContain('api-dock/spec');
});

it('wires the document transformers onto the generator config api-dock reads', function () {
    // DocumentGenerator generates from getGeneratorConfig(scrambleApi()); the
    // bearer transformer is appended to Scramble::configure(). Same instance or
    // the security scheme silently never reaches the panel's document.
    expect(Scramble::getGeneratorConfig(ApiDockServiceProvider::scrambleApi()))
        ->toBe(Scramble::configure());
});

it('exposes the api response envelope extension to the type-to-schema pipeline', function () {
    expect((array) config('scramble.extensions'))->toContain(ApiResponseExtension::class);
});
