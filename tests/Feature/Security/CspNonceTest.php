<?php

/*
|--------------------------------------------------------------------------
| SecurityHeaders — CSP nonce mode (starter-kit.security.csp_nonce)
|--------------------------------------------------------------------------
|
| script-src historically shipped 'unsafe-inline', which means any inline
| <script> that reaches the page executes. The only inline script the kit
| actually needs is the FOUC-killer theme script in app.blade.php, so the flag
| swaps 'unsafe-inline' for a per-request 'nonce-<random>'.
|
| The flag is OPT-IN because it can break a working install: a browser IGNORES
| 'unsafe-inline' the moment a nonce appears in script-src, so an app whose
| PUBLISHED app.blade.php predates the nonce attribute silently loses its theme
| script the second the flag flips. Hence what this file locks:
|
|   1. Flag OFF -> the policy is byte-for-byte the one shipped today. The whole
|      literal is asserted, not a substring, so no future edit to csp() can
|      change what an existing install receives without turning this red.
|   2. Flag ON  -> script-src carries 'nonce-...' and NO 'unsafe-inline';
|      style-src keeps 'unsafe-inline' (PrimeVue writes inline styles at
|      runtime and cannot be nonce'd) and the Turnstile origin survives both
|      branches.
|   3. The nonce is minted BEFORE $next() and is exactly the value
|      Vite::cspNonce() hands Blade during that same request. Minting it after
|      $next() would still produce a valid-looking header while the page
|      carries no matching attribute — a silent break with no error anywhere.
|   4. A stale published config (a PARTIAL `security` array, which replaces the
|      vendor block for every nested key) resolves to the class constant, and
|      that constant reproduces the shipped literal.
|
| The middleware is invoked directly through handle() — no route stack. Helper
| names are prefixed `csp*` because Pest hoists test-file functions to global
| scope and SecurityHeadersTest.php already owns `securityHeaders*`.
|
*/

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
use Lvntr\StarterKit\Http\Middleware\SecurityHeaders;

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Neutralise storage-origin derivation so the emitted policy is deterministic
 * and can be asserted as a whole literal. Origin derivation itself is covered
 * by SecurityHeadersTest; here it would only add noise to the diff.
 */
function cspNonceIsolateOrigins(): void
{
    config([
        'media-library.disk_name' => 'public',
        'filesystems.disks.public' => ['driver' => 'local'],
        'starter-kit.security.csp_extra_origins' => [],
    ]);
}

/**
 * Run the middleware and report BOTH the response and the nonce that was
 * visible to the "view" (the $next closure stands in for rendering).
 *
 * @return array{0: Response, 1: string|null}
 */
function cspNonceRun(?Response $prepared = null): array
{
    $seenByView = null;

    $response = (new SecurityHeaders)->handle(
        Request::create('https://app.example.test/admin'),
        function () use (&$seenByView, $prepared): Response {
            $seenByView = Vite::cspNonce();

            return $prepared ?? new Response('ok');
        },
    );

    return [$response, $seenByView];
}

function cspNonceHeader(): string
{
    return (string) cspNonceRun()[0]->headers->get('Content-Security-Policy');
}

/**
 * The single `name ...` directive out of a policy string, or '' when absent.
 */
function cspDirective(string $policy, string $name): string
{
    foreach (explode('; ', $policy) as $directive) {
        if (str_starts_with($directive, $name.' ')) {
            return $directive;
        }
    }

    return '';
}

// ──────────────────────────────────────────────────────────────────────────────
// 1. Flag OFF — today's policy, byte for byte
// ──────────────────────────────────────────────────────────────────────────────

it('emits the pre-nonce policy verbatim while the flag is off', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => false]);

    expect(cspNonceHeader())->toBe(
        "default-src 'self'; ".
        "base-uri 'self'; ".
        "frame-ancestors 'self'; ".
        "object-src 'none'; ".
        "form-action 'self'; ".
        'img-src \'self\' data: blob:; '.
        'media-src \'self\' data: blob:; '.
        "font-src 'self' data:; ".
        "style-src 'self' 'unsafe-inline'; ".
        "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com; ".
        "connect-src 'self' https://challenges.cloudflare.com; ".
        'frame-src https://challenges.cloudflare.com'
    );
});

it('mints no nonce at all while the flag is off', function (): void {
    config(['starter-kit.security.csp_nonce' => false]);

    [, $seenByView] = cspNonceRun();

    expect($seenByView)->toBeNull()
        ->and(Vite::cspNonce())->toBeNull();
});

// ──────────────────────────────────────────────────────────────────────────────
// 2. Flag ON — nonce replaces 'unsafe-inline' in script-src only
// ──────────────────────────────────────────────────────────────────────────────

it('replaces unsafe-inline with a nonce in script-src when the flag is on', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => true]);

    $scriptSrc = cspDirective(cspNonceHeader(), 'script-src');

    expect($scriptSrc)->toMatch("#^script-src 'self' 'nonce-[A-Za-z0-9]{40}' https://challenges\.cloudflare\.com$#")
        ->not->toContain("'unsafe-inline'");
});

it('keeps style-src unsafe-inline and the Turnstile origins in nonce mode', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => true]);

    $policy = cspNonceHeader();

    // PrimeVue writes element style attributes at runtime; a nonce cannot cover
    // those, so dropping this would blank the panel's styling.
    expect(cspDirective($policy, 'style-src'))->toBe("style-src 'self' 'unsafe-inline'")
        ->and(cspDirective($policy, 'script-src'))->toContain('https://challenges.cloudflare.com')
        ->and(cspDirective($policy, 'connect-src'))->toContain('https://challenges.cloudflare.com')
        ->and(cspDirective($policy, 'frame-src'))->toContain('https://challenges.cloudflare.com');
});

// ──────────────────────────────────────────────────────────────────────────────
// 3. The nonce the header advertises is the one the view received
// ──────────────────────────────────────────────────────────────────────────────

it('advertises exactly the nonce Vite handed the view during the same request', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => true]);

    [$response, $seenByView] = cspNonceRun();

    $policy = (string) $response->headers->get('Content-Security-Policy');

    // Non-null proves the nonce existed BEFORE the response was built. Had the
    // middleware called useCspNonce() after $next(), the header below would
    // still look correct while Blade printed nothing.
    expect($seenByView)->toBeString()
        ->and(cspDirective($policy, 'script-src'))->toContain("'nonce-{$seenByView}'");
});

it('mints a fresh nonce per request', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => true]);

    [, $first] = cspNonceRun();
    [, $second] = cspNonceRun();

    // A nonce reused across requests is worth little more than 'unsafe-inline'
    // — it stays guessable to anyone who saw one page.
    expect($first)->not->toBe($second);
});

// ──────────────────────────────────────────────────────────────────────────────
// 4. Environment / already-set-policy branches
// ──────────────────────────────────────────────────────────────────────────────

it('leaves a policy set by another layer untouched in nonce mode', function (): void {
    config(['starter-kit.security.csp_nonce' => true]);

    $prepared = new Response('ok');
    $prepared->headers->set('Content-Security-Policy', "default-src 'none'");

    [$response] = cspNonceRun($prepared);

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
});

it('still mints the nonce in local, where no policy is emitted', function (): void {
    $this->app['env'] = 'local';
    config(['starter-kit.security.csp_nonce' => true]);

    [$response, $seenByView] = cspNonceRun();

    // Deliberate: whether a policy is emitted is only known AFTER the response
    // exists, so the nonce is minted eagerly. A nonce attribute with no
    // matching policy is inert; the reverse order is a broken page.
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($seenByView)->toBeString();
});

// ──────────────────────────────────────────────────────────────────────────────
// 5. Stale published config
// ──────────────────────────────────────────────────────────────────────────────

it('falls back to the class constant when a partial security block hides the key', function (): void {
    cspNonceIsolateOrigins();

    // mergeConfigFrom merges TOP-LEVEL keys only: a published file carrying a
    // partial `security` array replaces the vendor one wholesale, so csp_nonce
    // reads null on every install that published before this release.
    config(['starter-kit.security' => ['csp_extra_origins' => []]]);

    expect(config('starter-kit.security.csp_nonce'))->toBeNull()
        ->and(cspDirective(cspNonceHeader(), 'script-src'))
        ->toBe("script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com");
});

it('keeps the shipped config literal and the class constant in sync', function (): void {
    expect(config('starter-kit.security.csp_nonce'))->toBe(SecurityHeaders::CSP_NONCE_DEFAULT)
        ->and(SecurityHeaders::CSP_NONCE_DEFAULT)->toBeFalse();
});

// ──────────────────────────────────────────────────────────────────────────────
// 6. The consuming end — the published Blade prints that same nonce
// ──────────────────────────────────────────────────────────────────────────────
//
// Everything above proves the header is right. This section proves the page is,
// which is the half that fails SILENTLY: a nonce in script-src with no matching
// attribute on the FOUC-killer script produces no error, no blocked request in
// the network tab — just a panel that opens in the wrong theme and flashes on
// every load. The stub that consumers actually receive is rendered here, inside
// the middleware pass, so the two values are compared for the SAME request.

/**
 * Render stubs/resources/views/app.blade.php exactly as shipped, during a
 * SecurityHeaders pass.
 *
 * `@vite` is put in hot mode (a hot file makes it emit dev-server tags instead
 * of reading a build manifest that does not exist in this package) and `$page`
 * is the Inertia payload the real view receives.
 *
 * @return array{0: string, 1: Response, 2: string|null} rendered HTML, response, nonce seen by the view
 */
function cspNonceRenderShippedBlade(): array
{
    $blade = (string) file_get_contents(dirname(__DIR__, 3).'/stubs/resources/views/app.blade.php');

    $hotFile = tempnam(sys_get_temp_dir(), 'sk-vite-hot');
    file_put_contents($hotFile, 'http://localhost:5173');
    Vite::useHotFile($hotFile);

    $html = '';
    $seenByView = null;

    try {
        $response = (new SecurityHeaders)->handle(
            Request::create('https://app.example.test/admin'),
            function () use (&$html, &$seenByView, $blade): Response {
                $seenByView = Vite::cspNonce();
                $html = Blade::render($blade, [
                    'page' => ['props' => ['appearance' => ['dark_mode_default' => true, 'theme' => 'aura']]],
                ]);

                return new Response($html);
            },
        );
    } finally {
        @unlink($hotFile);
    }

    return [$html, $response, $seenByView];
}

/**
 * The nonce attribute on the kit's own inline theme script, identified by the
 * IIFE that follows it — Vite's hot-mode tags carry a nonce too and would
 * otherwise be indistinguishable.
 */
function cspNonceThemeScriptAttribute(string $html): ?string
{
    return preg_match('/<script nonce="([^"]*)">\s*\(function \(\)/', $html, $m) === 1 ? $m[1] : null;
}

it('prints the advertised nonce on the theme script of the shipped Blade', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => true]);

    [$html, $response, $seenByView] = cspNonceRenderShippedBlade();

    $policy = (string) $response->headers->get('Content-Security-Policy');

    // The exact join the feature stands on: same request, same value, both ends.
    expect($seenByView)->toBeString()
        ->and(cspNonceThemeScriptAttribute($html))->toBe($seenByView)
        ->and(cspDirective($policy, 'script-src'))->toContain("'nonce-{$seenByView}'")
        ->and(cspDirective($policy, 'script-src'))->not->toContain("'unsafe-inline'");
});

it('renders the theme script itself, not just the attribute', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => true]);

    [$html] = cspNonceRenderShippedBlade();

    // Guards the assertion above against a Blade that "passes" because it broke:
    // the script must still carry its resolved appearance payload.
    expect($html)->toContain('var darkDefault = true')
        ->and($html)->toContain('var theme = "aura"');
});

it('leaves the nonce attribute empty and inert while the flag is off', function (): void {
    cspNonceIsolateOrigins();
    config(['starter-kit.security.csp_nonce' => false]);

    [$html, $response] = cspNonceRenderShippedBlade();

    // An existing install renders nonce="" — harmless, because a policy with no
    // nonce-source keeps honouring 'unsafe-inline'. This pins that the attribute
    // shipping ahead of the flag costs an existing app nothing.
    expect(cspNonceThemeScriptAttribute($html))->toBe('')
        ->and(cspDirective((string) $response->headers->get('Content-Security-Policy'), 'script-src'))
        ->toBe("script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com");
});

// ──────────────────────────────────────────────────────────────────────────────
// 7. Who gets the flag: install-time only
// ──────────────────────────────────────────────────────────────────────────────

it('seeds the flag through the first-install-only env mechanism', function (): void {
    $root = dirname(__DIR__, 3);

    // Same path as STARTER_KIT_ALLOW_UNRESOLVED_ROUTES: the value lives in the
    // shipped .env.example and the installer's merge skips it unless the
    // install is brand new. A key absent from that list would be handed to
    // every app that re-runs sk:install after an update.
    expect(file_get_contents($root.'/stubs/.env.example'))->toContain("\nSTARTER_KIT_CSP_NONCE=true")
        ->and(file_get_contents($root.'/src/Console/Commands/InstallCommand.php'))
        ->toContain("'STARTER_KIT_CSP_NONCE',");
});

it('reads the flag from the env key the installer seeds', function (): void {
    // config:cache freezes the resolved value, so this asserts the wiring in the
    // shipped config file rather than runtime env() behaviour.
    expect(file_get_contents(dirname(__DIR__, 3).'/config/starter-kit.php'))
        ->toContain("'csp_nonce' => (bool) env('STARTER_KIT_CSP_NONCE', SecurityHeaders::CSP_NONCE_DEFAULT)");
});
