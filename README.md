# Lvntr Starter Kit

### Admin-first Laravel starter kit.

![Tests](https://img.shields.io/badge/tests-passing-22c55e?style=flat-square)
![License](https://img.shields.io/badge/license-PolyForm--Noncommercial%201.0.0-f59e0b?style=flat-square)
![Packagist Version](https://img.shields.io/packagist/v/lvntr/laravel-starter-kit?style=flat-square&label=packagist)
![Downloads](https://img.shields.io/packagist/dt/lvntr/laravel-starter-kit?style=flat-square&label=downloads)

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
    - Settings panel (General / Auth / Mail / Storage / File Manager)
    - File Manager with pluggable contexts
    - API Routes explorer
    - Definitions (DB-backed enums used across forms and tables)
- **Developer Tooling**
    - DDD-style domain layer (Actions / DTOs / Queries / Events / Listeners)
    - FormBuilder, DatatableBuilder, TabBuilder fluent APIs
    - Domain scaffolding via `make:sk-domain`
    - Safe upgrade flow via `sk:update` (hash-tracked, preserves your edits)
    - Light & Dark themes

## How to use it?

Start from a clean Laravel install:

```bash
composer create-project laravel/laravel my-app
cd my-app
composer require lvntr/laravel-starter-kit:^13.0
php artisan sk:install
```

That's it. The installer sets up migrations, seeders, Passport keys, a default admin user, and builds the frontend.

Full step-by-step guide: [starter-kit.lvntr.dev/docs/install](https://starter-kit.lvntr.dev/)

## Requirements

- PHP 8.3+
- Laravel 13
- Node.js 18+
- MySQL or MariaDB

## Documentation

Everything — installation, update flow, domain scaffolding, FormBuilder / DatatableBuilder / TabBuilder APIs, composables, file manager, roles & permissions, OAuth2 API, activity logs, settings — lives on the official site:

**[starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/)**

## License

[PolyForm Noncommercial 1.0.0](./LICENSE)
