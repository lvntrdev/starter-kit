# Lvntr Starter Kit

![Tests](https://img.shields.io/badge/tests-passing-22c55e?style=flat-square)
![License](https://img.shields.io/badge/license-PolyForm--Noncommercial%201.0.0-f59e0b?style=flat-square)
![Packagist Version](https://img.shields.io/packagist/v/lvntr/laravel-starter-kit?style=flat-square&label=packagist)
![Downloads](https://img.shields.io/packagist/dt/lvntr/laravel-starter-kit?style=flat-square&label=downloads)

Lvntr Starter Kit is under active development, and each release is shaping it into a more complete admin-first Laravel platform.

Detailed usage docs: [kit-docs.lvntr.dev](https://kit-docs.lvntr.dev/)

A full-featured Laravel admin panel package built with **Laravel 13**, **Inertia.js v3**, **Vue 3**, **PrimeVue 4**, and **Tailwind CSS 4**. Follows DDD (Domain-Driven Design) architecture with built-in role-based permissions, activity logging, settings management, and more.

## Features

- **DDD Architecture** — Actions, DTOs, Queries, Events, Listeners
- **Role & Permission Management** — Spatie Permission with dynamic resource-scoped permissions
- **User Management** — CRUD with avatar upload, soft deletes, 2FA support
- **Activity Logging** — Spatie Activity Log with a browsable admin interface
- **Settings Panel** — General, Auth, Mail, Storage settings stored in database
- **OAuth2 API** — Laravel Passport with personal access tokens and device authorization
- **Domain Scaffolding** — `make:sk-domain` command generates full DDD stack interactively
- **FormBuilder / DatatableBuilder / TabBuilder** — Reusable Vue component builders
- **Multi-language Support** — Translation files included, easily extendable
- **API Response Builder** — Fluent, consistent API responses with pagination support
- **Security Headers Middleware** — X-Frame-Options, HSTS, CSP and more

## Tech Stack

### Backend (PHP / Composer)

| Package                  | Purpose                                                                  |
| ------------------------ | ------------------------------------------------------------------------ |
| **Laravel 13**           | Core framework (constraint: `^13.0`)                                     |
| **Inertia.js v3**        | Server-driven SPA — no API layer needed between backend and frontend     |
| **Laravel Fortify**      | Authentication backend (login, register, 2FA, password reset)            |
| **Laravel Passport**     | OAuth2 API authentication (personal access tokens, device authorization) |
| **Laravel Wayfinder**    | Type-safe route generation for TypeScript                                |
| **Spatie Permission**    | Role & permission management with dynamic resource-scoped permissions    |
| **Spatie Activity Log**  | Model activity logging with browsable admin interface                    |
| **Spatie Media Library** | File uploads & media collections (avatars, attachments)                  |
| **Spatie Query Builder** | Filter, sort, and include relationships via query string                 |
| **Spatie Translatable**  | Multi-language model attributes (JSON-based)                             |

### Frontend (Node / npm)

| Package              | Purpose                                                     |
| -------------------- | ----------------------------------------------------------- |
| **Vue 3**            | Reactive UI framework                                       |
| **PrimeVue 4**       | UI component library (DataTable, Dialog, Toast, Menu, etc.) |
| **Tailwind CSS 4**   | Utility-first CSS framework                                 |
| **Inertia.js Vue 3** | Client-side adapter for Inertia SPA                         |
| **VueUse**           | Collection of Vue composition utilities                     |
| **laravel-vue-i18n** | Use Laravel translation files directly in Vue               |

### Dev Tools

| Tool                    | Purpose                           |
| ----------------------- | --------------------------------- |
| **Vite**                | Frontend build tool with HMR      |
| **TypeScript**          | Type safety for frontend code     |
| **ESLint + Prettier**   | Code linting and formatting       |
| **Vitest**              | Unit testing for Vue components   |
| **Husky + lint-staged** | Pre-commit hooks for code quality |
| **Commitizen**          | Conventional commit messages      |

## Requirements

- PHP 8.3+
- Laravel 13
- Node.js 18+
- MySQL / PostgreSQL / SQLite

## Installation

### 1. Require the package

```bash
composer require lvntr/laravel-starter-kit:^13.0
```

### 2. Run the install command

```bash
php artisan sk:install
```

This interactive wizard will:

1. Publish all application scaffolding (Controllers, Models, Routes, Vue pages, etc.)
2. Publish the package config file
3. Run database migrations
4. Run seeders (Roles, Permissions, Definitions, Settings)
5. Generate Passport encryption keys
6. Create a default admin user
7. Install npm dependencies and build frontend assets

**Non-interactive mode (CI/CD):**

```bash
php artisan sk:install --no-interaction
```

**Overwrite existing files:**

```bash
php artisan sk:install --force
```

### 3. Configure your `.env`

```env
APP_NAME="My Application"
APP_URL=https://my-app.test

DB_CONNECTION=mysql
DB_DATABASE=my_app
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Access the admin panel

Open your browser and navigate to your app URL. Log in with the admin credentials shown after installation (default: `admin@demo.com` / `password`).

## Updating

When a new version of the package is released:

```bash
composer update lvntr/laravel-starter-kit
php artisan sk:update
```

The update command uses a **hash-based tracking system** to safely update files:

- **Core files** (BaseAction, BaseDTO, Traits, Middleware, helpers) are always updated
- **User-modifiable files** (Controllers, Pages, Routes) are only updated if you haven't changed them
- **New files** from the package are automatically added
- **New migrations** are detected and optionally run

**Preview changes before applying:**

```bash
php artisan sk:update --dry-run
```

**Force update everything (overwrites your changes):**

```bash
php artisan sk:update --force
```

## Publishing Optional Assets

The package keeps Vue components, language files, and config inside the package by default. If you need to customize them, publish them to your project:

```bash
# Interactive selection
php artisan sk:publish

# Publish Vue components (FormBuilder, DatatableBuilder, etc.)
php artisan sk:publish --tag=components

# Publish language files
php artisan sk:publish --tag=lang

# Publish config file
php artisan sk:publish --tag=config
```

## Available Commands

| Command            | Description                                       |
| ------------------ | ------------------------------------------------- |
| `sk:install`       | Full installation wizard                          |
| `sk:update`        | Update package files preserving user changes      |
| `sk:upgrade`       | Upgrade from previous Laravel version             |
| `sk:publish`       | Publish optional assets for customization         |
| `site:install`     | Reset database and reinstall with default data    |
| `make:sk-domain`   | Scaffold a complete DDD domain interactively      |
| `remove:sk-domain` | Remove a domain and all its files                 |
| `env:sync`         | Sync .env keys to .env.example                    |

### Domain Scaffolding

Create a new domain with all DDD layers:

```bash
# Interactive mode
php artisan make:sk-domain

# With options
php artisan make:sk-domain Product --fields="name:string,price:decimal" --admin --api --events --vue=full
```

This generates: Model, Migration, Factory, DTO, Actions, Events, Listeners, Controllers, FormRequests, Routes, and Vue pages.

Remove a domain:

```bash
php artisan remove:sk-domain Product
```

## Architecture

### Package Structure

```
lvntr/laravel-starter-kit/
├── src/                          # Core package code (never published)
│   ├── StarterKitServiceProvider.php
│   ├── Console/Commands/         # sk:install, sk:update, make:sk-domain, etc.
│   ├── Domain/Shared/            # BaseAction, BaseDTO, ActionPipeline
│   ├── Enums/                    # PermissionEnum
│   ├── Http/Middleware/          # CheckResourcePermission, SecurityHeaders
│   ├── Http/Responses/           # ApiResponse builder
│   ├── Support/                  # Package support classes
│   ├── Traits/                   # HasActivityLogging, HasMediaCollections
│   └── helpers.php               # to_api(), format_date()
├── resources/
│   ├── js/components/            # Vue components (optionally publishable)
│   └── lang/                     # Translation files (optionally publishable)
├── stubs/                        # Published to app on install
│   ├── app/                      # Controllers, Models, Domain, Providers, Enums
│   ├── config/                   # permission-resources.php, settings.php
│   ├── database/                 # Migrations, Seeders, Factories
│   ├── routes/                   # Web & API routes
│   ├── resources/js/             # Vue pages, Layouts, Composables, Theme
│   └── bootstrap/                # app.php, providers.php
└── config/
    └── starter-kit.php           # Package configuration
```

### Application Structure (after install)

```
app/
├── Domain/                       # DDD business logic
│   ├── User/                     # Actions, DTOs, Queries, Events, Listeners
│   ├── Role/
│   ├── Auth/
│   ├── Setting/
│   ├── ActivityLog/
│   └── Shared/                   # Base classes (updated by package)
├── Http/
│   ├── Controllers/Admin/        # Admin panel controllers
│   ├── Controllers/Api/          # REST API controllers
│   └── Middleware/
├── Models/
├── Enums/
└── Providers/
```

### Update Strategy

| File Category                                 | Behavior on `sk:update`                   |
| --------------------------------------------- | ----------------------------------------- |
| `Domain/Shared/`, Traits, Middleware, helpers | Always updated                            |
| Controllers, Models, Pages, Routes            | Updated only if user hasn't modified them |
| User's custom domains                         | Never touched                             |
| New files from package                        | Automatically added                       |

## Using Package Components

### Vue Components (without publishing)

Components are auto-resolved from the package. Use them in your Vue files:

```vue
<template>
    <SkForm :config="formConfig" />
    <SkDatatable :config="tableConfig" />
    <SkTabs :config="tabConfig" />
</template>
```

### Translations

```php
// From package namespace
__('starter-kit::admin.menu.dashboard')
__('starter-kit::message.created')
```

### Base Classes

```php
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Domain\Shared\DTOs\BaseDTO;
use Lvntr\StarterKit\Enums\PermissionEnum;
use Lvntr\StarterKit\Traits\HasActivityLogging;
```

## License

[PolyForm Noncommercial 1.0.0](./LICENSE)
