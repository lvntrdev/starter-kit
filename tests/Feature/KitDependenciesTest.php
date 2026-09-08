<?php

use Lvntr\StarterKit\Support\KitDependencies;

test('returns an array', function () {
    expect(KitDependencies::missing())->toBeArray();
});

test('an installed kit dependency never appears as missing', function () {
    expect(KitDependencies::missing())->not->toContain('dedoc/scramble');
});

test('php and ext-* entries are never reported as missing packages', function () {
    $missing = KitDependencies::missing();

    expect($missing)->not->toContain('php');

    foreach ($missing as $name) {
        expect($name)->not->toStartWith('ext-');
    }
});
