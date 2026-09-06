<?php

namespace Lvntr\StarterKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Nonce-mode default, used when the config key is absent.
     *
     * mergeConfigFrom merges TOP-LEVEL keys only. A `config/starter-kit.php`
     * published before this release carries no `csp_nonce` key — either no
     * `security` block at all (vendor block inherited whole) or a PARTIAL one
     * that replaces the vendor array for every nested key. Both read null here
     * and must land on the SAME value the shipped config file states, so this
     * constant reproduces that literal exactly. Do not let the two drift.
     *
     * `false` is also the only safe default: a published `app.blade.php`
     * without `nonce="{{ Vite::cspNonce() }}"` loses its inline theme script
     * the moment a nonce enters script-src, because a browser drops
     * `'unsafe-inline'` as soon as it sees one.
     */
    public const CSP_NONCE_DEFAULT = false;

    /**
     * Handle an incoming request and append security headers to the response.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // The nonce must exist BEFORE the view renders — Blade prints it via
        // Vite::cspNonce() while building the response. Generating it after
        // $next() would still produce a valid header token, but the page would
        // carry no matching attribute and every inline script would die
        // silently. Whether the header is actually emitted is only knowable
        // afterwards (local env, or a CSP another layer already set), so the
        // nonce is minted eagerly: a nonce attribute with no matching policy
        // is inert in every browser, the reverse is a broken page.
        $nonce = $this->nonceEnabled() ? Vite::useCspNonce() : null;

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self)');

        // CSP is only applied in non-local environments — Vite HMR in local
        // dev needs to load scripts/styles/websockets from a dev server URL
        // that varies per developer, so a tight CSP there blocks normal
        // work without adding security value.
        if (! app()->environment('local') && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->csp($nonce));
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    /**
     * Baseline CSP for non-local environments.
     *
     * Remote storage (S3 / DigitalOcean Spaces / a CDN set as a disk `url`)
     * serves FileManager previews and settings assets from its own origin, so
     * the origins of the media-library disk and the public disk are appended
     * to img-src / media-src / connect-src — a same-origin-only policy would
     * make the browser block every cloud-hosted preview and download. Other
     * origins (e.g. remote images embedded in the welcome message) can be
     * allowed via `starter-kit.security.csp_extra_origins`.
     *
     * @param  string|null  $nonce  Per-request script nonce, or null when
     *                              `starter-kit.security.csp_nonce` is off — in
     *                              which case script-src keeps `'unsafe-inline'`
     *                              and the policy is byte-for-byte the one every
     *                              existing install already receives.
     */
    private function csp(?string $nonce = null): string
    {
        $extra = array_values(array_filter(
            (array) config('starter-kit.security.csp_extra_origins', []),
            static fn ($origin): bool => is_string($origin) && preg_match('#^https?://[^\s;]+$#', $origin) === 1,
        ));

        $origins = implode(' ', array_unique([...$this->storageOrigins(), ...$extra]));
        $suffix = $origins === '' ? '' : ' '.$origins;

        // A nonce and 'unsafe-inline' are mutually exclusive by design: the
        // browser ignores 'unsafe-inline' once a nonce is present, so leaving
        // it in would only mislead a reader of the header. Turnstile is a
        // remote src, unaffected by either — it must survive both branches.
        $scriptSrc = $nonce === null
            ? "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com"
            : "script-src 'self' 'nonce-{$nonce}' https://challenges.cloudflare.com";

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "form-action 'self'",
            "img-src 'self' data: blob:{$suffix}",
            "media-src 'self' data: blob:{$suffix}",
            "font-src 'self' data:",
            // style-src keeps 'unsafe-inline' in BOTH branches: PrimeVue writes
            // element style attributes at runtime and Vue's own scoped styles
            // land inline, neither of which a nonce can cover.
            "style-src 'self' 'unsafe-inline'",
            $scriptSrc,
            "connect-src 'self' https://challenges.cloudflare.com{$suffix}",
            'frame-src https://challenges.cloudflare.com',
        ]);
    }

    /**
     * Whether script-src runs in nonce mode. An absent key (stale published
     * config) resolves to the documented default.
     */
    private function nonceEnabled(): bool
    {
        $configured = config('starter-kit.security.csp_nonce');

        if ($configured === null) {
            return self::CSP_NONCE_DEFAULT;
        }

        return (bool) $configured;
    }

    /**
     * Origins the configured storage disks serve files from.
     *
     * Considered disks: the media-library disk (FileManager previews and
     * temporary URLs) and the public disk (settings assets such as logos).
     * Per disk, in order:
     *   - a non-empty `url` (CDN or remote base) contributes its origin;
     *   - an s3 `endpoint` contributes the endpoint origin plus a `*.host`
     *     wildcard for bucket-subdomain style URLs (DigitalOcean Spaces,
     *     MinIO with virtual hosts);
     *   - plain AWS (driver s3, no endpoint) contributes the region/bucket
     *     origins `https://{bucket}.s3.{region}.amazonaws.com` and the
     *     path-style `https://s3.{region}.amazonaws.com`.
     *
     * @return array<int, string>
     */
    private function storageOrigins(): array
    {
        $origins = [];

        $disks = array_unique(array_filter(
            [config('media-library.disk_name'), 'public'],
            static fn ($disk): bool => is_string($disk) && $disk !== '',
        ));

        foreach ($disks as $disk) {
            $cfg = config("filesystems.disks.{$disk}");

            if (! is_array($cfg)) {
                continue;
            }

            if (is_string($cfg['url'] ?? null) && $cfg['url'] !== '') {
                $origins[] = $this->origin($cfg['url']);
            }

            if (($cfg['driver'] ?? null) !== 's3') {
                continue;
            }

            if (is_string($cfg['endpoint'] ?? null) && $cfg['endpoint'] !== '') {
                $endpoint = $this->origin($cfg['endpoint']);
                $origins[] = $endpoint;
                $origins[] = $this->wildcardSubdomain($endpoint);
            } elseif (is_string($cfg['region'] ?? null) && $cfg['region'] !== '') {
                $bucket = is_string($cfg['bucket'] ?? null) && $cfg['bucket'] !== '' ? $cfg['bucket'] : null;

                $origins[] = "https://s3.{$cfg['region']}.amazonaws.com";

                if ($bucket !== null) {
                    $origins[] = "https://{$bucket}.s3.{$cfg['region']}.amazonaws.com";
                }

                // us-east-1 additionally answers on the SDK's legacy global
                // endpoint, and that is what signed URLs commonly carry there.
                // Omitting it leaves CSP blocking previews on a configuration
                // the kit fully supports.
                if ($cfg['region'] === 'us-east-1') {
                    $origins[] = 'https://s3.amazonaws.com';

                    if ($bucket !== null) {
                        $origins[] = "https://{$bucket}.s3.amazonaws.com";
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($origins)));
    }

    /**
     * Scheme + host (+ explicit port) of a URL, or null when it has no
     * parseable scheme/host (relative and malformed values contribute
     * nothing to the policy).
     */
    private function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = "{$parts['scheme']}://{$parts['host']}";

        if (isset($parts['port'])) {
            $origin .= ":{$parts['port']}";
        }

        return $origin;
    }

    /**
     * `https://host` → `https://*.host`, covering bucket-subdomain URLs
     * that s3 clients build from a bare endpoint.
     */
    private function wildcardSubdomain(?string $origin): ?string
    {
        if ($origin === null) {
            return null;
        }

        return preg_replace('#^(https?://)#', '$1*.', $origin);
    }
}
