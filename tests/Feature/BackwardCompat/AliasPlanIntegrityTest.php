<?php

/*
|--------------------------------------------------------------------------
| Backward Compatibility — alias plan bütünlüğü
|--------------------------------------------------------------------------
|
| backwardCompatAliasPlan() merges an unconditional $plan array with a much
| larger $overridable array of App\ → vendor class-string pairs (121+ entries).
| Two failure modes this test guards against, neither of which would surface
| as a PHP fatal error at edit time:
|
|   1. A duplicate literal key across the two arrays. PHP arrays silently
|      keep the LAST value on a duplicate key, so a copy/paste mistake would
|      silently drop one alias with no runtime signal. Caught by parsing the
|      method's OWN SOURCE (not the merged runtime array, which has already
|      deduped by the time PHP evaluates it).
|   2. A typo'd FQCN. Most entries are `SomeClass::class` (a compile error
|      would catch a typo there), but the v13.6.0+ blocks deliberately use FQ
|      STRING LITERALS ("App\Http\Controllers\Admin\LogController" =>
|      'Lvntr\...') to avoid import churn — a typo on either side of a
|      string-literal pair is invisible until a consumer app boots and hits
|      the aliased class. Caught by asserting every vendor-side value
|      resolves via class_exists() / interface_exists().
|
*/

use Lvntr\StarterKit\StarterKitServiceProvider;

/**
 * Raw source text of backwardCompatAliasPlan(), used to inspect literal array
 * keys BEFORE PHP merges/dedupes them at runtime.
 */
function aliasPlanMethodSource(): string
{
    $method = new ReflectionMethod(StarterKitServiceProvider::class, 'backwardCompatAliasPlan');
    $lines = file($method->getFileName());

    return implode('', array_slice(
        $lines,
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));
}

/**
 * The resolved plan (as it would appear at runtime) with NO app/ overrides
 * present, so every "overridable" entry is included — the fullest possible
 * key/value set to check.
 *
 * @return array<class-string, class-string>
 */
function resolvedAliasPlanForIntegrityCheck(): array
{
    $method = new ReflectionMethod(StarterKitServiceProvider::class, 'backwardCompatAliasPlan');

    $provider = new StarterKitServiceProvider(app());

    /** @var array<class-string, class-string> */
    return $method->invoke($provider, sys_get_temp_dir().'/sk-alias-plan-integrity-'.uniqid());
}

it('declares no duplicate literal array key in backwardCompatAliasPlan()', function (): void {
    preg_match_all('/\'([A-Za-z0-9_\\\\]+)\'\s*=>/', aliasPlanMethodSource(), $matches);

    $keys = $matches[1];

    expect($keys)->not->toBeEmpty();

    $counts = array_count_values($keys);
    $duplicates = array_keys(array_filter($counts, fn (int $count): bool => $count > 1));

    expect($duplicates)->toBe([], 'Duplicate alias key(s) in backwardCompatAliasPlan(): '.implode(', ', $duplicates));
});

it('resolves every vendor-side alias target to a real class or interface (catches FQCN string-literal typos)', function (): void {
    $plan = resolvedAliasPlanForIntegrityCheck();

    expect($plan)->not->toBeEmpty()
        ->and($plan)->toHaveKey('App\Http\Responses\ApiResponse');

    foreach ($plan as $appClass => $vendorClass) {
        expect(class_exists($vendorClass) || interface_exists($vendorClass))->toBeTrue(
            "backwardCompatAliasPlan() maps {$appClass} => {$vendorClass}, but that vendor class/interface does not exist (typo?)."
        );
    }
});

it('never aliases an App\\ key to itself', function (): void {
    foreach (resolvedAliasPlanForIntegrityCheck() as $appClass => $vendorClass) {
        expect($appClass)->not->toBe($vendorClass);
    }
});
