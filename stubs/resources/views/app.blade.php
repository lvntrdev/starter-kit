<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{--
            FOUC killer — resolve dark mode + runtime theme on <html> BEFORE the
            first paint, so a user's stored dark choice (or the admin global
            theme/dark default) never flashes from the light/`main` server state
            on every load / hard refresh.

            Server-resolved global defaults come from the Inertia `appearance`
            shared prop already embedded in $page (HandleInertiaRequests::share),
            so no extra query is issued here. The resolution MIRRORS
            useDarkMode.ts + useTheme.ts + useAppearanceDefaults.ts EXACTLY
            (localStorage key `admin-dark-mode`, legacy-migration rule, and the
            user-override → admin-global-default precedence). Those composables'
            onMounted handlers re-apply the SAME value, so this script is
            idempotent — it sets the final state and mounting never flips it.

            CSP note: this is the ONE inline <script> the kit ships, so it is the
            one thing standing between the panel and a script-src without
            'unsafe-inline'. `starter-kit.security.csp_nonce` (env
            STARTER_KIT_CSP_NONCE) governs that switch: with the flag ON the
            SecurityHeaders middleware mints a per-request nonce before this view
            renders and puts 'nonce-<random>' in script-src instead of
            'unsafe-inline'; the attribute below is what makes this tag survive it.

            While the flag is OFF the attribute is INERT, not harmful: Vite has no
            nonce, so it prints `nonce=""`, and a policy that carries no
            nonce-source keeps honouring 'unsafe-inline' regardless of what the
            element declares. Do NOT delete the attribute to "clean up" a
            flag-off install — removing it is exactly what makes the theme script
            vanish silently (wrong theme, FOUC, no console error) the day the flag
            is turned on.
        --}}
        @php
            $skAppearance = data_get($page ?? [], 'props.appearance', []);
            $skDarkDefault = (bool) data_get($skAppearance, 'dark_mode_default', false);
            $skTheme = is_string(data_get($skAppearance, 'theme')) ? data_get($skAppearance, 'theme') : 'main';
        @endphp
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                try {
                    var el = document.documentElement;

                    // Dark mode: user override (localStorage) wins over the admin
                    // global default. Mirrors useDarkMode.ts + the one-time
                    // migrateLegacyAppearanceStorage() cleanup: a stored 'false'
                    // that predates that migration is legacy noise (not a real
                    // choice) and resolves to the global default, exactly as the
                    // composable will once it mounts.
                    var darkDefault = @json($skDarkDefault);
                    var stored = null, migrated = false;
                    try {
                        stored = window.localStorage.getItem('admin-dark-mode');
                        migrated = window.localStorage.getItem('admin-appearance-migrated') !== null;
                    } catch (e) {}

                    var dark;
                    if (stored === null || (stored === 'false' && !migrated)) {
                        dark = darkDefault;            // no explicit user choice
                    } else {
                        dark = stored === 'true';      // VueUse boolean serializer
                    }
                    el.classList.toggle('dark', dark);

                    // Runtime theme: admin global default only (no per-user
                    // override). Mirrors useTheme.ts — only a runtime theme other
                    // than 'main' sets `data-sk-theme`; 'main' and build-time
                    // custom themes leave it absent. The runtime set matches the
                    // client's DEFAULT_RUNTIME_THEMES fallback (`runtime_themes`
                    // is stripped from the global appearance share).
                    var theme = @json($skTheme);
                    var runtimeThemes = ['main', 'aura'];
                    if (runtimeThemes.indexOf(theme) !== -1 && theme !== 'main') {
                        el.setAttribute('data-sk-theme', theme);
                    } else {
                        el.removeAttribute('data-sk-theme');
                    }
                } catch (e) {}
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        {{-- Fallback carries no version number on purpose: it went stale at every release. --}}
        <title data-inertia>{{ config('app.name', 'Starter Kit') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Figtree:ital,wght@0,300..900;1,300..900&display=swap"
              rel="stylesheet"> --}}

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
</head>
<body class="font-sans antialiased">
@inertia
</body>
</html>
