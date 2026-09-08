# Welcome to Lvntr Starter Kit

Lvntr Starter Kit is a modern starting point for adding a usable admin-panel foundation to Laravel projects from day one. Authentication, authorization, settings, file management, API access, and developer tooling all ship inside a single, consistent structure.

## Production-Ready Admin Foundation

User, role, permission, activity-log, settings, and dashboard screens come ready out of the box. It cuts down the admin scaffolding rebuilt over and over in new projects and lets the team focus on business logic faster.

## Modern Laravel Stack

The package is built on Laravel 13, PHP 8.4+, Inertia.js v3, Vue 3, PrimeVue 4, and Tailwind CSS 4. It offers a current, extensible, and packaged architecture on both the backend and frontend.

## Secure Authentication

Fortify-based login, registration, password reset, email verification, and two-factor authentication flows are supported. Session management and Cloudflare Turnstile integration strengthen the security layer.

## Roles and Permissions

Role-based access control is provided through Spatie Permission. With resource-scoped permissions, admin screens and actions can be managed dynamically according to the user's role.

## Settings Panel

General, authentication, mail, storage, file-manager, API integration, API client/token, and System Health surfaces are managed from a single panel. It provides a central place to control application behavior without changing code.

## File Management

The File Manager supports core file operations such as media upload, foldering, trash, restore, and signed share links. Its context-based structure adapts to different module and ownership scenarios.

## API and Token Management

OAuth2 API access, API client management, and personal access token flows are supported through Laravel Passport. API clients and tokens can be controlled from the admin panel. Endpoints can also carry [AI-oriented metadata](./api-ai-metadata.md) — hints, pitfalls, examples, and a changelog — exported as `llms.txt` and MCP tool definitions.

## System Health

The `sk:doctor` command and the **Settings → System Health** tab check the status of critical points such as the database, Redis, Passport keys, storage link, mail, queue, and build artifacts.

## DDD-Oriented Project Structure

With Action, DTO, Query, Event, and Listener layers, business logic is separated into a more readable and testable shape. The `make:sk-domain` command scaffolds new domains quickly and consistently.

## Builder Components

The FormBuilder, DatatableBuilder, and TabBuilder APIs make it easy to build reusable admin interfaces. Needs such as translatable fields, bulk actions, and cross-page selection are supported out of the box.

## Activity Logs and Traceability

Important domain events such as user and role operations are recorded to the activity log. Trace IDs and structured API responses provide extra context for error tracking and operational traceability.

## Safe Update Flow

`sk:update` manages stub updates with hash tracking and focuses on preserving user changes. With `sk:publish`, only the resources you actually need can be published selectively.

## Ready to Start, Flexible to Extend

The starter kit gives the project a ready admin backbone while leaving room to extend the core structure. Teams can build their own product features faster instead of rewriting the same baseline screens.
