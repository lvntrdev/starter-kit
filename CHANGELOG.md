# Changelog

All notable changes to `lvntr/laravel-starter-kit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

---

## [13.1.4] - 2026-04-12

### Added

- Granular component publishing — `sk:publish` now supports individual tags (`--tag=datatable`, `--tag=form`, `--tag=tabs`, `--tag=skeleton`, `--tag=ui`). Multiple tags can be combined: `--tag=datatable --tag=ui`.
- Interactive multi-select prompt when running `sk:publish` without arguments.

---

## [13.1.0] - 2026-04-11

### Added

- **`sk:upgrade` command** — New Artisan command for upgrading between starter kit versions.
- **Multi-language support** — Turkish language pack with translations for admin panel, auth, buttons, datatables, messages, validation, and pagination.
- **Locale switching** — `LocaleController` and `SetLocale` middleware for runtime language switching via header UI.
- **AI development tooling** — Pre-configured Claude Code skills (Laravel best practices, Inertia Vue, Pest testing, Wayfinder, Passport, Pulse, Tailwind, domain architecture, code reviewer, changelog generator, frontend design) and agent definitions.
- **Commitizen config** — `.cz-config.cjs` stub for conventional commit workflow.

### Changed

- **Package renamed** from `lvntr/starter-kit` to `lvntr/laravel-starter-kit`.
- **Laravel 12 support dropped** — Now requires Laravel 13+ exclusively.

### Fixed

- Remaining hardcoded `lvntr/starter-kit` references replaced with `lvntr/laravel-starter-kit`.

---

## [13.0.0] - 2026-04-05

### Added

- **Laravel 13 support** — `illuminate/support` constraint updated to support Laravel 13.

### Changed

- Dual Laravel 12/13 support in this initial v13 release (Laravel 12 support removed in v13.1.0).

---

## [12.0.0] - 2026-03-19

### Added

- **Initial release** of the Lvntr Starter Kit package.
- **`sk:install` command** — Interactive installation wizard that scaffolds the entire admin panel:
  - Database driver selection and configuration
  - Stub publishing (controllers, models, migrations, views, routes, config)
  - Automatic dependency installation and build
  - Admin user creation with role assignment
- **`sk:publish` command** — Publish components, language files, or configuration for customization.
- **`sk:update` command** — Update package stubs with version-aware diff handling.
- **`sk:make:domain` command** — Generate DDD domain modules (Actions, DTOs, Queries, Events, Listeners).
- **Vue component library:**
  - `SkDatatable` — Server-side datatable with sorting, filtering, pagination, bulk actions, cell slots, and refresh bus
  - `SkForm` / `SkFormInput` — Declarative form builder with 15+ field types, validation, file uploads, conditional fields
  - `SkColorSelector` — Color picker input component
  - `SkTabs` — Horizontal and vertical tab layouts with URL sync
  - `SkTag` — Tag/badge component with outlined variant
  - `PageLoading`, `SkeletonBox`, `SkeletonCard`, `SkeletonTable`, `SkeletonText` — Skeleton loading components
  - `AppDialog` — Modal dialog component
  - `AvatarUpload` — Avatar image upload with preview
  - `ConfirmDialogComponent` — Confirmation dialog wrapper
  - `ToastComponent` — Toast notification component
- **Admin panel stubs:**
  - Dashboard, User management (CRUD + avatar + 2FA), Role & permission management
  - Settings panel (General, Auth, Mail, Storage tabs)
  - Activity log viewer with detail modal
  - API routes viewer
  - Profile page (info, two-factor, sessions tabs)
- **Auth stubs** — Login, Register, Forgot/Reset password, Email verification, Two-factor challenge, Confirm password
- **DDD architecture stubs** — Domain modules for User, Role, Setting, ActivityLog with Actions, DTOs, Queries
- **API foundation** — `ApiResponse` builder, `ApiException` handler, Passport token authentication
- **Enum system** — Database-backed definitions with admin management via `DefinitionService`
- **Permission seeder** — `SeedPermissionsCommand` for resource-scoped permission generation
- **PrimeVue 4 theme** — Custom preset with dark mode support
- **SSR support** — Server-side rendering configuration out of the box
- **Wayfinder integration** — Type-safe route generation stubs
- **Service provider** — Auto-registration of components, commands, routes, and views

### Fixed

- Install command: overwrite mode as default behavior
- `ApiResponse` namespace conflict resolved
- Timezone default configuration
- SSR tab activation in settings
- Missing CSS theme files added to stubs
- Role name corrected to `system_admin` (underscore)
- `UserStatus` enum: string value for admin user creation
- Missing core stubs added (Traits, Domain/Shared, Enums, ApiResponse)
- SQLite removed from database driver options
- Database configuration step added, pulse migration removed from initial install
