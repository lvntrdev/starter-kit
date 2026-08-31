# Lvntr Starter Kit

### Admin-first Laravel starter kit.

![CI](https://img.shields.io/github/actions/workflow/status/lvntrdev/laravel-starter-kit/ci.yml?branch=main&style=flat-square&label=CI)
![License](https://img.shields.io/badge/license-MIT-3b82f6?style=flat-square)
![Packagist Version](https://img.shields.io/packagist/v/lvntr/laravel-starter-kit?style=flat-square&label=packagist)
![Downloads](https://img.shields.io/packagist/dt/lvntr/laravel-starter-kit?style=flat-square&label=downloads)

> ## ⚠️ WARNING
>
> This repository is under active development and is subject to frequent changes. The stability of the project is not yet guaranteed. Please consider the following points before use:
>
> 1. **Code Changes:** The directory structure or core classes may undergo radical changes without prior notice.
> 2. **Update Process:** Updates may not always provide an automated migration path. In addition to running update commands, you may need to perform manual interventions by checking the `README` or `CHANGELOG` files.
> 3. **Risk:** Significant changes may lead to data loss or breaking issues in your existing project.

## Introduction

Lvntr Starter Kit is a full-featured admin panel for Laravel, built with **Laravel 13**, **Inertia.js v3**, **Vue 3**, **PrimeVue 4** and **Tailwind CSS 4**.

Unlike the official Laravel starter kits, which ship a minimal authentication scaffold, this kit gives you a production-ready admin panel on day one: users, roles, permissions, activity logs, settings, file manager, 2FA, and a DDD-style domain layer you can extend.

It is designed for teams who want to skip re-building the same admin screens on every project and go straight to business features.

> **Website & Documentation:** [starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/)
> Installation guide, component references, architecture notes and examples.

## Screenshots

![Dark & Light themes](https://starter-kit.lvntr.dev/shots/dark-light.png)

![Login screen](https://starter-kit.lvntr.dev/shots/auth-login.png)

![User management](https://starter-kit.lvntr.dev/shots/admin-users.png)

![Roles & permissions](https://starter-kit.lvntr.dev/shots/admin-permissions.png)

![File manager](https://starter-kit.lvntr.dev/shots/admin-file-manager.png)

## What is Inside?

- **Authentication**
    - Login / Register / Password Reset
    - Email Verification
    - Two-Factor Authentication (Fortify)
    - OAuth2 API with Laravel Passport
- **User & Access Management**
    - User CRUD with avatar upload and soft deletes
    - Roles & dynamic resource-scoped permissions (Spatie)
    - Session management
- **Admin Modules**
    - Dashboard
    - Activity Logs (browsable, filterable)
    - Settings panel (General / Auth / Mail / Storage / File Manager / Content Languages)
    - Multi-language content: active languages managed in Settings drive every [Translatable Field](./docs/translatable-fields.md) form-wide, no rebuild required
    - File Manager with pluggable contexts and signed share links
    - API Clients & Personal Access Token management
    - System Health dashboard
    - API Routes explorer
    - Definitions (DB-backed enums used across forms and tables)
- **Developer Tooling**
    - DDD-style domain layer (Actions / DTOs / Queries / Events / Listeners)
    - FormBuilder, DatatableBuilder, TabBuilder fluent APIs (including [Translatable Fields](./docs/translatable-fields.md))
    - `@lvntr/components` Vue component library (FormBuilder/DatatableBuilder/TabBuilder, UI primitives, File Manager UI) — not published on npm; resolved via a Vite alias into the package's own `vendor/` copy, so no separate install step is needed
    - Domain scaffolding via `make:sk-domain` with opt-in flag support
    - Datatable bulk actions with cross-page selection
    - Safe upgrade flow via `sk:update` (hash-tracked, preserves your edits)
    - System health check via `sk:doctor`
    - Dedicated data-encryption key for sensitive settings & 2FA secrets, independent of `APP_KEY` — generate/rotate/verify with `encryption:key`, `encryption:rekey`, `encryption:health`
    - Light & Dark themes with instant-switch built-in `main` and `aura` kit themes (no rebuild)

## How to use it?

Start from a clean Laravel install:

```bash
composer create-project laravel/laravel my-app
cd my-app
composer require lvntr/laravel-starter-kit:^13.7
php artisan sk:install
```

> **Check `php -v` first — this kit requires PHP 8.4+.** The `laravel/laravel`
> skeleton itself only requires PHP 8.3, so `create-project` succeeds on 8.3 and
> the failure surfaces later. Always require the kit with `:^13.7` (not a looser
> `:^13.0`): with a loose constraint Composer silently resolves down to an
> ancient release that still fits PHP 8.3 instead of reporting the real blocker.

That's it. The installer sets up migrations, seeders, Passport keys, a default admin user, and builds the frontend. It also ejects the `User` and `Role` domain runtime classes into `app/Domain/` so they are immediately project-owned and ready to customise. Pass `--without-eject` to keep them vendor-resident instead.

Full step-by-step guide: [starter-kit.lvntr.dev/docs/install](https://starter-kit.lvntr.dev/docs/install)

## Requirements

- PHP 8.4+ (hard floor — `spatie/laravel-activitylog:^5.0` requires it too)
- Laravel 13
- Node.js 20.19+ (or 22.12+) — Vite 7 engine floor
- MySQL or MariaDB

## Compatibility & Versioning

The package version major aligns with the supported Laravel major. Each
Laravel major gets its own maintenance branch and `vN.x.y` tag stream;
existing consumer constraints stay locked to their major and never
receive breaking changes from a newer Laravel target.

| Laravel | Constraint                                            | Branch  | Status      |
|---------|-------------------------------------------------------|---------|-------------|
| 13.x    | `composer require lvntr/laravel-starter-kit:^13.7`    | `13.x`  | active      |

`main` tracks the currently active major (today: `13.x`). When a future
Laravel release is targeted, `main` will move to that next-major dev
stream and the previous major's `N.x` branch will continue to receive
backports.

The **git tag is the single source of version truth** — neither
`composer.json` nor the root `package.json` carries a `version` field, so
there is nothing to keep in sync. Releases are cut with `release.sh` (from
`main`), which tags the release and pushes only that tag.

## Documentation

Everything — installation, update flow, domain scaffolding, FormBuilder / DatatableBuilder / TabBuilder APIs, composables, file manager, roles & permissions, OAuth2 API, activity logs, settings — lives on the official site:

**[starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/)**

## License

[MIT](./LICENSE)
