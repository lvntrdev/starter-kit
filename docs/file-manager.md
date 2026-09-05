# File Manager

A reusable Windows Explorer-style file management UI. Any Eloquent model can own a folder tree — two contexts ship out of the box (`user`, `global`) and new ones (`vehicle`, `school`, `project`, …) plug in via the [ContextRegistry](#custom-contexts) without touching the component.

Built on Spatie MediaLibrary with a logical folder layer — all folder/file moves are metadata-only (no physical file movement).

## Main Capabilities

- sidebar with quick-access list (All Files, Recently Uploaded, Favorites, Trash), folder tree, and a circular storage-usage ring
- top-bar search that filters the current folder client-side
- top stats widget (Total Files, Total Size, Folder Count, Favorites, Last Upload)
- nested folder browsing via grid + breadcrumb
- folder create, rename, delete (cascade)
- favorite folders/files and browse the Favorites quick view
- soft-delete Trash workflow with restore, permanent delete, and Empty Trash
- file copy and file rename actions
- file upload with per-tile progress
- drag-and-drop move between folders
- bulk delete
- download plus fullscreen image lightbox / inline preview modal
- file details dialog (name, type, size, uploaded date, folder, image dimensions)
- share link via clipboard
- keyboard shortcuts (`Ctrl+A`, `Delete`, `Esc`, `Enter`)

## Imports

```ts
import FileManager from '@lvntr/components/FileManager/FileManager.vue';
```

## Basic Usage

### User context (per-user files)

```vue
<FileManager context="user" :context-id="user.id" height="100%" />
```

### Global context (admin-scoped files)

```vue
<FileManager context="global" height="100%" />
```

### Custom context (any Eloquent model)

```vue
<FileManager context="vehicle" :context-id="vehicle.id" height="100%" />
```

`vehicle` here is either a morph-map alias, a name that resolves to `App\Models\Vehicle` via convention, or an explicit `ContextRegistry::register()` entry. See [Custom contexts](#custom-contexts) for the resolution order.

## Props

| Prop            | Type                           | Default   | Description                                                                 |
| --------------- | ------------------------------ | --------- | --------------------------------------------------------------------------- |
| `context`       | `'user' \| 'global' \| string` | required  | Context key registered with `ContextRegistry` (built-ins: `user`, `global`) |
| `contextId`     | `string \| null`               | `null`    | Owner primary key — required for contexts whose path contains `{id}`        |
| `readonly`      | `boolean`                      | `false`   | Disable all mutations (upload/delete/rename/move)                           |
| `enableTrash`   | `boolean`                      | config    | Move deletes to Trash; falls back to `config('file-manager.settings.enable_trash')` (default `true`). Pass explicitly to override the config value. |
| `acceptedMimes` | `string[]?`                    | settings  | Override accepted MIME list                                                 |
| `maxSizeKb`     | `number?`                      | settings  | Override max upload size                                                    |
| `height`        | `string`                       | `'600px'` | CSS height of the shell; pass `100%` to fill a flex parent                  |

## Layout

The shell is a column with three regions:

1. **Top bar** — a single-row search box (`IconField` + `InputText`) that filters the current folder's files and folders client-side by name.
2. **Body** — a flex row split into:
    - **Sidebar** (`FileManagerSidebar`) — circular storage-usage ring, quick-access list, folder tree, "New Folder" button.
    - **Main column** — stats widget (`FileManagerStats`) + breadcrumb + grid of tiles.

`height` controls the column height; the sidebar and main column share the remaining space inside that height.

## Features

### Sidebar (`FileManagerSidebar`)

- **Storage usage ring** — SVG circle showing `usedBytes / quotaBytes` as a percent. Colour bands: primary < 70 %, amber 70–90 %, rose ≥ 90 %. Used bytes come from `fm.contents.stats.total_size`. The quota is currently a sane visual default of 10 GB until a backend setting is wired up; the ring still scales correctly when the value changes.
- **Quick access** — four entries:
    - **All Files** — root folder, sorted by name asc.
    - **Recently Uploaded** — root folder, sorted by date desc.
    - **Favorites** — virtual view backed by the `file_favorites` table.
    - **Trash** — virtual view of soft-deleted folders and File Manager media; hidden when `enableTrash=false`.
- **Folder tree** — same nested data the move modal already loads (`fm.tree`); clicking a node is equivalent to navigating into the folder via the grid.
- **New Folder** — inline button that opens the same create-folder dialog as the empty-state "New Folder" hint.

### Stats widget (`FileManagerStats`)

A horizontal row of icon-tinted cards above the breadcrumb:

| Card         | Source                                                                                                     |
| ------------ | ---------------------------------------------------------------------------------------------------------- |
| Total Files  | `fm.contents.stats.file_count`                                                                             |
| Total Size   | `fm.contents.stats.total_size` (human-formatted)                                                           |
| Folder Count | the entire nested tree (`flattenTree(fm.tree.value).length`)                                               |
| Favorites    | rendered from the File Manager stats prop; currently used as a quick visual counter                        |
| Last Upload  | most-recent `created_at` in the current folder, formatted as "Just now / X min / X hr / X d / locale-date" |

### Search

The top-bar search box filters the **current folder's** rendered tiles only; navigating to a different folder implicitly resets the filter on the next `fm.loadContents()`. Filter is case-insensitive `includes` against `folder.name` / `file.file_name`.

### Tiles & selection

- **Selection** — always-visible top-right checkbox on every tile (primary-filled when selected, outline-on-hover when not):
    - Folder tile plain click → single-select, `double-click` → open
    - File tile plain click → open image lightbox for images, preview modal for other previewable types
    - `Ctrl/Cmd + click` (both) → toggle the item in/out of the current selection
    - Empty grid drag → rubber-band across tiles
    - Right-click on empty area → **Select All**
    - Right-click on a tile never forces it into the selection
- **Type-aware previews** — image thumbnails, plus color-coded icons for PDF / Word / Excel / Video / Audio / Archive / Text.
- **Empty state** — outlined folder illustration plus "Upload" / "New Folder" hints.

### Preview flow

- File click (or context **Preview**) uses the fullscreen `ImageLightbox` for images, and a 90vw preview dialog for PDF, video, audio, text, and other previewable non-image files. Unrecognised types fall back to **Open in new tab** + **Download** buttons.
- The dedicated **Open** entry in the file context menu now opens the file in a new browser tab (`noopener,noreferrer`) regardless of MIME type, side-by-side with **Preview**.

### Upload

- **Per-tile progress** — uploads stream per-file via XHR; each dropped/selected file appears immediately as an optimistic placeholder tile with a filling progress bar. Failed uploads stay as dismissable error tiles; successful ones slot in when the listing refreshes.
- **Full-area external drop zone** — dragging OS files anywhere over the FileManager surface shows the upload overlay. Internal tile drags don't trigger it (distinguished via the `Files` data-transfer type).

### Favorites

- Every non-trash folder/file tile has a star toggle in the top-left corner.
- Folder and file context menus also expose Add/Remove Favorite.
- Favorites are scoped to the same context owner as the files and folders, so a user's favorites never bleed into another user/global/custom context.
- The Favorites quick view is virtual: it lists favorited folders and files from the current context, but it does not aggregate subtree stats.

### Trash, restore and permanent delete

- With `enableTrash=true` (default), deleting a file or folder soft-deletes it and it appears in the Trash quick view.
- In Trash, context menus switch to **Restore** and **Permanently Delete**. Regular open/move/favorite actions are hidden.
- Restoring a folder restores its descendant folders and File Manager media in a transaction. If its parent folder is still trashed, restore is refused until the parent is restored first; if the parent was permanently deleted, the item is restored to root.
- **Empty Trash** permanently deletes all trashed File Manager items in the current context. Files are removed before folders, and folders are deleted child-first.
- `php artisan file-manager:purge-trash --days=7` permanently deletes File Manager trash older than the configured age. It is scheduled daily by the shipped `routes/console.php`.
- Set `:enable-trash="false"` to bypass Trash and use immediate permanent deletes. In this mode single-item deletes call the permanent-delete endpoint directly, and selected-item deletes send `force_delete=true` to the bulk-delete endpoint.

### Move, bulk delete, rename, copy, share, details

- **Drag-and-drop move** — tiles are `draggable`; dropping onto a folder tile moves the whole selection there.
- **Move modal** — both context menus expose **Move**; the dialog shows a `FolderTree` picker (supports moving to the root), handles single and bulk sources.
- **Bulk delete** — toolbar button when anything is selected, or right-click a selected item → **Delete Selected (N)**. With Trash enabled this soft-deletes active items; in Trash, or when `enableTrash=false`, it sends `force_delete=true` and permanently deletes the selected items.
- **Rename** — folder and file context menus open a rename dialog. Duplicate names in the same folder are rejected server-side.
- **Copy** — file context menu **Duplicate** creates a physical MediaLibrary copy in the current folder (or supplied target folder) with copy-safe names such as `photo (copy).jpg` / `photo (copy 2).jpg`.
- **Share** — file context menu **Share** copies the absolute file URL to the clipboard via `navigator.clipboard.writeText(...)` and surfaces a localised "Link copied" toast on success. If clipboard permission is refused, the localised "coming soon" toast surfaces instead.
- **Details** — file context menu **Details** opens `FileDetailsDialog` showing Name, Type, Size, Uploaded, Folder and (for images) Dimensions. Image dimensions are loaded async via a hidden `new Image()`. The dialog has a **Download** footer button that re-uses the same handler as the right-click menu.
- **Busy overlay** — Delete / Move / Rename operations paint a modal card over the FileManager area with a spinner, title and — for bulk ops — a "N items remaining" counter plus a **Stop** button that cancels the remaining iterations.

### Context menus

Right-click on folder / file / empty area; rounded card with separators between groups and a dedicated `fm-menu-danger` class on the destructive Delete row:

- **Folder** — Open, Rename, Move, Add/Remove Favorite, Delete.
- **File** — Open (in new tab), Preview, Download, Share, Move, Duplicate, Rename, Add/Remove Favorite, Details, Delete.
- **Trash folder/file** — Restore, Permanently Delete.
- **Empty** — New Folder, Upload, Select All, Refresh.

### Keyboard

- `Enter` on a focused tile — open it
- `Ctrl/Cmd + A` — select all items in the current folder
- `Delete` / `Backspace` — delete the current selection (with confirmation)
- `Esc` — clear the selection
- All shortcuts are suppressed while typing in inputs or while any dialog is open.

## Route Surface

All endpoints accept `context` and `context_id` as query string on GET/DELETE or body on POST/PATCH.

| Method | Path                                                 | Purpose                                                             |
| ------ | ---------------------------------------------------- | ------------------------------------------------------------------- |
| GET    | `/file-manager/tree`                                 | Entire nested folder tree for the context                           |
| GET    | `/file-manager/contents?folder_id=&sort=&direction=` | Folder contents + stats                                             |
| GET    | `/file-manager/favorites/contents`                   | Favorited folders/files for the context                             |
| POST   | `/file-manager/favorites`                            | Add a folder/file to favorites (`item_type`, `item_id`)             |
| DELETE | `/file-manager/favorites`                            | Remove a folder/file from favorites (`item_type`, `item_id`)        |
| GET    | `/file-manager/trash/contents`                       | Soft-deleted folders/files for the context                          |
| DELETE | `/file-manager/trash/empty`                          | Permanently delete all trashed items in the context                 |
| POST   | `/file-manager/items/restore`                        | Restore one trashed folder/file (`item_type`, `item_id`)            |
| DELETE | `/file-manager/items/permanent`                      | Permanently delete one folder/file, active or trashed (`item_type`, `item_id`) |
| POST   | `/file-manager/folders`                              | Create folder (`parent_id`, `name`)                                 |
| PATCH  | `/file-manager/folders/{folder}`                     | Rename (`name`)                                                     |
| DELETE | `/file-manager/folders/{folder}`                     | Cascade delete (subfolders + media)                                 |
| PATCH  | `/file-manager/items/move`                           | Move a folder or file (`item_type`, `item_id`, `target_folder_id`)  |
| POST   | `/file-manager/items/bulk-delete`                    | Delete many (`items: [{type, id}]`, optional `force_delete=true`)    |
| POST   | `/file-manager/files`                                | Multipart upload, `throttle:30,1`                                   |
| PATCH  | `/file-manager/files/{media}`                        | Rename file (`name`)                                                |
| POST   | `/file-manager/files/{media}/copy`                   | Duplicate file (`target_folder_id`)                                 |
| DELETE | `/file-manager/files/{media}`                        | Delete one file                                                     |
| GET    | `/file-manager/files/{media}/download`               | Force-download                                                      |
| POST   | `/file-manager/share`                                | Create an HMAC-signed share link (`media_id`, `expires_in_hours?`)         |
| POST   | `/file-manager/share/revoke`                         | Revoke a share link (`token`)                                       |
| GET    | `/file-manager/share/{media}?expires=&signature=`    | Validate signature and serve the file                               |

### Upload parameters

`POST /file-manager/files` accepts these multipart fields beyond the file itself:

| Field         | Type          | Required          | Purpose                                                                         |
| ------------- | ------------- | ----------------- | ------------------------------------------------------------------------------- |
| `file`        | binary        | yes               | The file being uploaded                                                         |
| `context`     | string        | yes               | Context key (`user`, `global`, or a registered custom key)                      |
| `context_id`  | string        | context-dependent | Owner primary key when the context path contains `{id}`                         |
| `folder_id`   | uuid          | no                | Target folder inside the context; omit to upload to the root                    |
| `folder_name` | string (≤100) | no                | Ensure a root-level folder by this name exists and upload inside it (see below) |

When `folder_name` is supplied, `UploadFileAction::ensureManagedFolder` atomically ensures a root-level folder with that name exists and stores the upload inside it. The value is validated with `nullable|string|max:100|regex:/^[\pL\pN _-]+$/u` — letters, digits, space, dash, underscore only; path traversal and arbitrary characters are rejected at the request boundary. This is the mechanism `FB.editor()` uses via `imageUpload.folderName` to keep all inline image uploads grouped under a single folder per editor instance.

### Client-side error handling

The `useFileManager` composable maps upload failures to localised toasts. HTTP 413 (Payload Too Large) uses the dedicated `too_large` translation so the user knows the upload exceeded the server limit; every other non-200 response surfaces the raw status code alongside the backend message for faster triage.

## Custom Contexts

FileManager is not limited to `user` and `global` — any Eloquent model can be a context owner. The `ContextRegistry` resolves context keys in this order:

1. **Explicit registration** via `ContextRegistry::register()` (highest priority). `global` ships as a built-in inside the registry itself — no service-provider wiring needed.
2. **Laravel morph-map alias** — if the key appears in `Relation::morphMap()`, the mapped model class is used.
3. **`App\Models\{Studly(key)}` convention** — e.g. `context="vehicle"` → `App\Models\Vehicle` when that class exists.

The built-in `user` context is served entirely by auto-resolution (step 3) plus the shipped `UserPolicy` (self-access + `users.read` / `users.update` admin gate).

### Zero-config flow (no service-provider changes)

For a context backed by a normal model (`Vehicle`, `School`, `Project`, …) you don't need to touch `AppServiceProvider` or any config. Just:

**1.** Have `App\Models\Vehicle` — or register a morph-alias for it.

**2.** Drop a policy at `app/Policies/VehiclePolicy.php` (Laravel auto-discovers by name):

```php
namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function view(User $actor, Vehicle $vehicle): bool
    {
        return $actor->id === $vehicle->user_id
            || $actor->can('vehicles.read');
    }

    public function update(User $actor, Vehicle $vehicle): bool
    {
        return $actor->id === $vehicle->user_id
            || $actor->can('vehicles.update');
    }
}
```

**3.** Mount the component with the context key:

```vue
<FileManager context="vehicle" :context-id="vehicle.id" height="100%" />
```

Behind the scenes the auto-resolved context uses path `vehicle/{id}/files`. The default authorizer short-circuits for self-ownership (actor IS the owner — what makes `context="user"` work without extra config), otherwise delegates to Laravel policies: `$user->can('view', $vehicle)` for `read`, `$user->can('update', $vehicle)` for `create`, `update`, and `delete`. No policy → Laravel denies by default, so storage stays safe.

> The starter kit ships with a matching `app/Policies/UserPolicy.php` (self + `users.read` / `users.update`) so `context="user"` works out of the box. Use it as a template when writing policies for your own contexts.

### When to register explicitly

Use `ContextRegistry::register()` only when you need to override one of the defaults — a custom disk path, permission-based (not policy-based) auth, or a singleton resolver like the built-in `global` context:

```php
use App\Domain\FileManager\Support\ContextRegistry;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;

app(ContextRegistry::class)->register('vehicle', [
    'model' => Vehicle::class,
    'path' => 'vehicles/{id}/files',   // override default "vehicle/{id}/files"
    'resolve' => fn (?string $id) => Vehicle::query()->findOrFail($id),
    'authorize' => fn (Model $actor, string $ability, Model $owner): bool =>
        $actor->can("vehicles.{$ability}"),   // permission-based
]);
```

### Contract

A context definition has four parts:

| Field       | Type                                                         | Purpose                                                                                   |
| ----------- | ------------------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| `model`     | `class-string<Model>`                                        | Eloquent class stored as the polymorphic owner                                            |
| `path`      | `string`                                                     | Disk path template; `{id}` is replaced with the owner's primary key (omit for singletons) |
| `resolve`   | `Closure(?string $id): Model`                                | How to load the owner model from the incoming `context_id`                                |
| `authorize` | `Closure(Model $actor, string $ability, Model $owner): bool` | `$ability` is `'read'`, `'create'`, `'update'`, or `'delete'`; the kit never passes `'write'` |

Validation is driven by the registry — unknown keys that don't auto-resolve return 422. Contexts whose `path` contains `{id}` automatically require `context_id`.

## Storage Layout

`MediaPathGenerator` reads the path template from the context definition so every context gets a stable, folder-agnostic layout:

| Context                        | Path template         | Resolved example                                          |
| ------------------------------ | --------------------- | --------------------------------------------------------- |
| `user`                         | `user/{id}/files`     | `{disk}/user/{userId}/files/{mediaUuid}/{filename}`       |
| `global`                       | `global/files`        | `{disk}/global/files/{mediaUuid}/{filename}`              |
| auto-resolved (e.g. `vehicle`) | `{key}/{id}/files`    | `{disk}/vehicle/{vehicleId}/files/{mediaUuid}/{filename}` |
| custom registration            | anything you register | per the template you supply                               |

Moving a file between folders only updates `media.folder_id` in the DB — the file on disk never moves.

## Settings (Admin > Settings > File Manager)

| Key                           | Default                           | Description                        |
| ----------------------------- | --------------------------------- | ---------------------------------- |
| `file_manager.max_size_kb`    | `10240`                           | Max upload size per file (KB)      |
| `file_manager.accepted_mimes` | image / pdf / word / excel / text | Allowed MIME types                 |
| `file_manager.allow_video`    | `false`                           | Toggle to accept `video/*` uploads |
| `file_manager.allow_audio`    | `false`                           | Toggle to accept `audio/*` uploads |

Backend validation reads these settings on every upload request — bypassing the frontend mime filter still fails server-side.

### Signed Share Links (`share`)

HMAC-SHA256 signed links that grant time-limited access to a file without requiring authentication. Requires `config('file-manager.share.enabled')` to be `true`.

| Key                | Type | Default | Description                                      |
| ------------------ | ---- | ------- | ------------------------------------------------ |
| `enabled`          | bool | `true`  | Enable the share link feature                    |
| `default_ttl_hours`| int  | `24`    | Default link lifetime in hours                   |
| `max_ttl_hours`    | int  | `720`   | Maximum allowed lifetime (30 days)               |
| `allow_revoke`     | bool | `true`  | Allow links to be revoked before expiry          |

Revoked tokens are recorded in the `file_manager_share_revocations` table with a `(media_id, signed_token_hash)` composite unique index. A token is validated against its originating `media_id` — cross-media token reuse is rejected.

#### Trash and share link access

Soft-deleting a file (moving it to Trash) makes it immediately inaccessible:

- Any request that resolves `{media}` from a route — signed share show, authenticated download, rename, copy, delete — returns **404** for a trashed file. The response is identical to a non-existent file to avoid disclosing whether the file exists in Trash (no oracle).
- Creating a new share link for a trashed file also returns **404**.
- **Deleting does not automatically revoke existing share links.** If a signed link for the file is still valid (not expired, not revoked), it will return 404 while the file is trashed and resume working if the file is restored. To permanently block access regardless of restore, call `POST /file-manager/share/revoke` — revocation survives restore.
- Restoring a file re-enables access via any share links that have not expired or been revoked.
- `php artisan file-manager:purge-trash` permanently deletes the file from disk. After purge, even a non-revoked link returns 404 permanently.

**Route binding note:** The `{media}` route parameter is resolved using `Route::model('media', config('media-library.media_model'))` registered in the service provider. This means every route in the consumer application that uses a `{media}` parameter — including your own custom routes — receives an instance of the configured model class (with its SoftDeletes global scope applied). Trashed records are excluded from the binding by default; use `withTrashed()` in your own controllers when you explicitly need access to trashed records.

### Trash behaviour (`enable_trash`)

The `enable_trash` key in `config/file-manager.php` is the single source of truth for soft-vs-hard delete:

```php
// config/file-manager.php
'settings' => [
    'enable_trash' => true,  // set false for hard deletes everywhere
],
```

- **`true` (default)** — delete sends items to Trash (soft-delete). The Trash sidebar entry is visible. Restore and Empty Trash are available.
- **`false`** — all delete operations permanently remove the item immediately. The Trash sidebar entry is hidden.

Both the backend actions (`DeleteFileAction`, `DeleteFolderAction`) and the Vue component read this value. The config is shared automatically via Inertia shared props (`fileManagerSettings.enable_trash`) so the component does not need the `:enable-trash` prop unless you want to override the config value per-instance.

> **Trash is scoped to the FileManager `files` collection only.** Media in any other collection — avatars, logos, FormBuilder file-upload attachments, editor images — is always permanently deleted, regardless of `enable_trash`. The published `App\Models\Media` stub overrides `delete()` to convert those deletes into `forceDelete()`, which also covers deletes issued internally by Spatie (e.g. replacing a single-file collection via `clearMediaCollection()`). Without this, such records would pile up as invisible soft-deleted rows: they never appear in the Trash UI, the purge command skips them, they keep counting toward the storage quota, and their files linger on disk. `file-manager:purge-trash` additionally sweeps any legacy non-`files` trashed rows left over from older installs (no age limit).

## Permissions

The component itself does not check permissions — `FileManagerAuthorizer` is the only backend gate for these routes and checks every request. It calls the `authorize` closure on the resolved context definition with exactly one of four abilities:

| Ability | Operations | Built-in `global` permission |
| --- | --- | --- |
| `read` | tree, folder contents, favorites/trash listings, download | `files.read` |
| `create` | upload, create folder, copy file | `files.create` |
| `update` | rename, move, favorite toggle, restore, share/revoke context check | `files.update` |
| `delete` | file/folder/bulk delete, empty trash, permanent delete | `files.delete` |

Built-in rules:

- **User context** — allowed when the authenticated user IS the context user; otherwise its policy uses `users.read` for `read` and `users.update` for all mutations
- **Global context** — maps each ability one-to-one to the matching `files.*` permission; unknown abilities fail closed
- **Auto-resolved contexts** — delegate to Laravel policies: `$user->can('view', $owner)` for `read`, `$user->can('update', $owner)` for every mutation
- **Custom registrations** — receive `read`, `create`, `update`, or `delete`; the kit never passes the deprecated `write` ability

The `files` resource is seeded with `create / read / update / delete` abilities; assign these to roles via the Roles admin. For custom contexts, define a policy or pass a permission-based closure when registering.

Share link operations use two dedicated permissions:

- `share-media` — create a signed share link (`POST /file-manager/share`)
- `revoke-share-media` — revoke a share link before expiry (`POST /file-manager/share/revoke`)

## Related Stack

- Spatie Media Library
- custom `MediaPathGenerator` for stable per-context disk layout
- `FileManagerAuthorizer` for per-request authorization
- `ContextRegistry` + `ContextDefinition` in `src/Domain/FileManager/Support/` (vendor-resident; namespace `Lvntr\StarterKit\Domain\FileManager\Support\`)

## Full-Page Mounting

To make FileManager fill the admin page without a scrolled outer container:

```vue
<AdminLayout :title="$t('sk-file.title')">
    <div class="flex min-h-0 flex-1">
        <FileManager context="global" height="100%" class="flex-1" />
    </div>
</AdminLayout>
```

The `admin-content` CSS class is a flex column, so `flex min-h-0 flex-1` consumes the remaining vertical space below the page header, and `height="100%"` makes FileManager fill it. Inner scrolling happens inside the grid only.

## Composable Access (Advanced)

If you need to drive the component from the outside:

```ts
import { useFileManager } from '@lvntr/components/FileManager/composables/useFileManager';

const fm = useFileManager({ context: 'user', contextId: userId });

await fm.loadTree();
await fm.loadContents(null); // root
fm.setSort('size', 'desc');
```

Exposed state: `tree`, `contents`, `currentFolderId`, `breadcrumb`, `loading`, `sort`, `direction`, `selectedKeys`, `selectionCount`, `selectedItems`, `pendingUploads`.

Methods: `loadTree` / `loadContents` / `loadFavorites` / `loadTrash` / `refresh` / `setSort` / `toggleSortDirection` / `isSelected` / `toggleSelect` / `setSelection` / `clearSelection` / `selectAll` / `createFolder` / `renameFolder` / `renameFile` / `copyFile` / `toggleFavorite` / `restoreItem` / `permanentlyDeleteItem` / `emptyTrash` / `deleteFolder` / `deleteFile` / `bulkDelete` / `bulkForceDelete` / `moveItem` / `uploadFiles` / `dismissPending`.

`uploadFiles(files, folderId?)` returns `{ uploaded: FileItem[], errors: string[] }`. Per-file progress is exposed via the `pendingUploads` ref — each entry has `{ tempId, name, size, mimeType, progress, error, folderId }` and is removed automatically on success; errored entries remain until dismissed via `dismissPending(tempId)`.

## Recommended Usage

Use the file manager for admin-managed uploads and structured media flows. For simple single-file fields, pair it with FormBuilder file upload inputs.
