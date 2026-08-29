# FormBuilder

`SkForm` renders dynamic forms from a fluent configuration built with `FB`. It handles PrimeVue wiring, definition loading, dependent selects, file uploads, and permission-based read-only mode — so pages stay thin rendering layers.

## Imports

```ts
import { FB } from '@lvntr/components/FormBuilder/core';
import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
```

## Basic Usage

```vue
<script setup lang="ts">
    import { FB } from '@lvntr/components/FormBuilder/core';
    import SkForm from '@lvntr/components/FormBuilder/SkForm.vue';
    import users from '@/routes/users';

    const formConfig = FB.form()
        .cols(2)
        .cardTitle('sk-user.create')
        .submit({
            url: users.store.url(),
            method: 'post',
        })
        .addFields(
            FB.inputText().key('first_name'),
            FB.inputText().key('last_name'),
            FB.inputText().key('email').inputType('email'),
            FB.inputMask().key('phone').mask('(999) 999-9999').unmask(),
            FB.select().key('status').definitionOptions('userStatus').default('active'),
            FB.select().key('gender').definitionOptions('gender'),
            FB.password().key('password').toggleMask(),
        )
        .build();
</script>

<template>
    <SkForm :config="formConfig" />
</template>
```

## Two Operating Modes

### Internal submit mode

If `submit(...)` is configured, `SkForm` manages an internal Inertia `useForm()` instance.

### External model mode

If `submit(...)` is omitted, you can use `v-model` and handle submission yourself.

```vue
<SkForm v-model="formData" :config="formConfig" :errors="errors" />
```

## Form Builder API

- `layout('vertical' | 'horizontal')`
- `cols(number)` — grid column count for the form (1–12 are all supported; previously values above 6 silently fell back to the default 2-column layout)
- `dividers(boolean)` — in horizontal layout, draw hairline dividers between field rows and left-align labels (settings-style stacked rows). No effect in vertical layout. Default `false`.
- `class(string)`
- `dataUrl(url)`
- `reloadOnDataUrlChange(boolean)` — opt in to refetching `dataUrl` whenever it changes after mount (e.g. a dialog reused for a different record id). Default `false`: a form whose config is rebuilt on every parent render would otherwise refetch on every rebuild, and a same-URL rebuild must not wipe in-progress edits.
- `dataKey(key)`
- `initialData(record)`
- `actionsPosition('top' | 'bottom' | 'both')`
- `submit({ url, method, preserveScroll? })`
- `resource({ store, update, data, key, id? })` — shorthand that derives `submit`, `dataUrl`, and `dataKey` from a single config: truthy `id` → edit mode (PUT to `update`, data loaded from `data`); falsy `id` → create mode (POST to `store`)
- `actionLabels(...)`
- `hideCancel(boolean)`
- `hideSubmit(boolean)`
- `hideActions(boolean)` — suppress the whole action bar (top and bottom); the host drives submission via the form's exposed `submit()` method
- `onCancel('back' | 'emit')`
- `inDialog(boolean)`
- `showBack(boolean)`
- `cardTitle(string)`
- `cardSubtitle(string)`
- `isCard(boolean)`
- `permission(key)` — permission key that puts the form into read-only mode when the user lacks it (all fields disabled + submit hidden)
- `confirmLeave(boolean)` — warn before navigating away while the form is dirty (internal submit mode only). Default `true`; pass `false` to opt out.
- `addFields(...fields)`

## Common Field Methods

Most fields support:

- `key`
- `label`
- `trans(boolean)` — whether `label` is a translation key resolved via `$t()` (default `true`) or an already-resolved raw string. Pass `false` when supplying a pre-translated label (e.g. `.label($t('admin.example')).trans(false)`) so the template skips `$t()` on it.
- `required`
- `labelPlacement('top' | 'inline')` — label position in vertical layout. Default `'top'` (stacked above the control); `'inline'` places the label beside the control (the look `checkbox`/`toggle-button`/`toggle-switch` already use by default).
- `controlPosition('left' | 'right')` — position of the control relative to its label. Default `'left'`.
- `optional`
- `class`
- `hint`
- `visible(fn)`
- `disabled(fn)`
- `hidden(boolean)`
- `default(value)`
- `props({...})`
- `colSpan(number)` — how many grid columns this field occupies (1..cols). Omitting it defaults to 1 cell. Values exceeding the active `cols` are automatically clamped; inside a section, the section's own `cols` is used as the upper bound.

`hidden(true)` keeps the field in the submitted payload while rendering it as a hidden input instead of a visible control.

```ts
FB.inputText().key('user_id').default(currentUserId).hidden();
```

### Label `for` / control id convention

For most field types the rendered `<label for>` targets the field's own `key`. Six field types render their PrimeVue control inside a non-focusable wrapper element (`input-number`, `date-picker`, `select`, `multiselect`, `toggle-switch`, and `password` when `.feedback()` is enabled) — for those, the inner focusable control receives `${key}__control` as its id via PrimeVue's `inputId` prop, and the label's `for` targets that id instead of the wrapper. This is internal wiring (`core/ids.ts`'s `controlId()`); it only matters if you're reading rendered markup or writing a test that queries by label/id.



## Available Field Builders

- `FB.inputText()`
- `FB.inputNumber()`
- `FB.inputOtp()`
- `FB.inputMask()`
- `FB.datePicker()`
- `FB.select()`
- `FB.multiselect()`
- `FB.radio()`
- `FB.selectButton()`
- `FB.checkbox()`
- `FB.checkboxGroup()` — supports `optionsUrl` the same as `select`/`multiselect`/`radio`/`selectButton` (see [Dynamic Options from API](#dynamic-options-from-api-dependent-selects))
- `FB.password()`
- `FB.textarea()`
- `FB.editor()`
- `FB.translatableText()`
- `FB.translatableTextarea()`
- `FB.translatableEditor()`
- `FB.toggleButton()`
- `FB.toggleSwitch()`
- `FB.fileUpload()`
- `FB.colorSelector()`
- `FB.title()`
- `FB.section()`
- `FB.slot()`

## Icons (Package-Agnostic)

The kit does not depend on any specific icon library. All icon APIs (`icon`, `labelIcon`, `iconPosition`, `labelIconPosition`, title/section icon) accept a single `string` "icon descriptor."

The descriptor is auto-detected from one of three formats:

| Pattern | Meaning | Example |
| --- | --- | --- |
| Starts with `<svg…` | Raw SVG markup — rendered via `v-html` | `'<svg viewBox="0 0 24 24">…</svg>'` |
| Starts with `https?:` or `data:` | URL or data URI — rendered as `<img src>` | `'https://cdn.example.com/icon.svg'`, `'data:image/svg+xml;base64,…'` |
| Anything else | CSS class — rendered as `<i :class>` | `'pi pi-search'`, `'fa fa-user'`, `'mdi mdi-account'` |

This approach supports PrimeIcons, FontAwesome, Material Design Icons, Lucide, Iconify, and any other class-based icon set through the same API.

**Security note:** Icon descriptors must come from developer-controlled builder config — not from user input. The `<svg…` path renders via `v-html`, which is an XSS vector. Never pass an API-sourced string directly into a field's icon config without sanitization.

## Label Icons

`.labelIcon(descriptor)` adds an icon next to a field's label. Supported on all field types.

- `.labelIconPosition('left' | 'right')` — placement relative to the label text (default `'left'`).

```ts
FB.inputText()
    .key('email')
    .label('Email')
    .labelIcon('pi pi-envelope')
    .labelIconPosition('left')
```

Works in all layout modes: `vertical` (top label), `vertical` (inline label), and `horizontal`.

## Input Icons

`.icon(descriptor)` places an icon inside the input element using PrimeVue `IconField` + `InputIcon`.

- `.iconPosition('left' | 'right')` — placement (default `'left'`).

Supported field types: `input-text`, `input-number`, `input-mask`, `password` (custom path only — see note below).

```ts
FB.inputText().key('search').label('Search').icon('pi pi-search').iconPosition('left')
FB.inputNumber().key('price').label('Price').icon('fa fa-dollar').iconPosition('right')
FB.inputMask().key('phone').label('Phone').mask('(999) 999-9999').icon('mdi mdi-phone')
```

**Caveats:**

- `.groupPrefix()` / `.groupSuffix()` takes priority — if an InputGroup wrapper is present, the input icon is ignored.
- `FB.password().feedback()` renders via PrimeVue `<Password>` (strength meter path). The `.icon()` method has no effect on that path. When `feedback` is disabled (default custom path), `.icon()` works normally.
- `.icon()` is **not supported** on `select`, `multiselect`, `textarea`, `editor`, `file-upload`, `color-selector`, or `date-picker` (which has its own `showIcon` mechanism). Use `.labelIcon()` on those types, or customize via `componentProps`.

## InputMask Field API

`FB.inputMask()` is useful for values such as phone numbers, identity numbers, and formatted dates.

- `mask(string)`
- `placeholder(string | boolean)`
- `slotChar(string)`
- `autoClear(boolean)`
- `unmask(boolean)`

```ts
FB.inputMask().key('phone').mask('(999) 999-9999').placeholder('sk-common.placeholder.phone').slotChar('_').unmask();
```

When `unmask(true)` is enabled, the stored model value is returned without mask characters.

## DatePicker Field API

`FB.datePicker()` renders PrimeVue `DatePicker` for date, date-time, range, multiple-date, month, and year inputs.

- `placeholder(string | boolean)`
- `dateFormat(string)` — PrimeVue date format, default `'dd/mm/yy'`.
- `selectionMode('single' | 'range' | 'multiple')`
- `showTime(boolean)`
- `hourFormat('12' | '24')`
- `showIcon(boolean)`
- `iconDisplay('input' | 'button')`
- `minDate(Date)`
- `maxDate(Date)`
- `showButtonBar(boolean)`
- `numberOfMonths(number)`
- `view('date' | 'month' | 'year')`
- `inline(boolean)`

```ts
FB.datePicker()
    .key('published_at')
    .label('Published at')
    .showIcon()
    .showTime()
    .hourFormat('24')
    .dateFormat('dd/mm/yy');
```

## Password Field API

`FB.password()` renders a password input with an optional strength meter, a crypto-safe generator, and a consistent eye toggle.

- `toggleMask(boolean)` — show a show/hide eye toggle (default `true`).
- `feedback(boolean)` — opt in to the PrimeVue `<Password>` strength meter. When omitted, the field falls back to the lighter `<InputText>` + custom eye toggle path so it renders identically inside `InputGroup` containers. Default `false`.
- `generator(options?)` — opt in to a crypto-safe generate button placed next to the input. Options are optional:

    ```ts
    FB.password().key('password').generator();
    // → 16 chars, mixed case + letters + digits + symbols

    FB.password().key('password').generator({
        length: 20,
        mixedCase: true,
        letters: true,
        numbers: true,
        symbols: true,
    });
    ```

    Defaults are intentionally stricter than the framework-wide `Password::defaults()` rule so every generated value passes backend validation on the first submit. The generated password is written directly into the input, shown once via toast (`password_generated` / `password_generated_detail`), and can be copied from the field.

```ts
// Simple password field with eye toggle
FB.password().key('password');

// Password with generator button
FB.password().key('password').generator();

// Strength meter variant (falls back to PrimeVue <Password>)
FB.password().key('password').feedback();

// Generator with a custom length and symbol set
FB.password().key('password').generator({ length: 24 });
```

## Editor Field API

`FB.editor()` renders a Tiptap v3 WYSIWYG editor as a FormBuilder field. Content is stored as sanitized HTML — `App\Support\HtmlSanitizer` strips tags, attributes, and URL schemes outside the allowlist on both write and read paths.

- `toolbar('minimal' | 'standard' | 'full')` — toolbar layout. `minimal` covers bold / italic / link; `standard` adds headings, lists, alignment and color; `full` enables tables, task lists, image embedding and horizontal rule. Default `'standard'`.
- `placeholder(string)` — translation key rendered when the editor is empty.
- `minHeight(string)` — CSS `min-height` for the editor body (default `'10rem'`).
- `imageUpload({ context, contextId?, folderId?, folderName?, acceptedMimes? })` — configure inline image uploads through File Manager. `context` is required and must be registered in the File Manager context registry. `folderName` groups every image uploaded through this editor under a single root-level folder in that context (e.g. every welcome-message image goes under "Welcome Message"). Accepts the same regex as the server-side `folder_name` validator: letters, digits, space, dash, underscore only.
- `links(boolean)` — enables the link toolbar button and paste auto-linking. Default `false`.
- `treatEmptyAsBlank(boolean)` — emits an empty string instead of `<p></p>` when the editor is empty. Default `true`.

```ts
FB.editor()
    .key('welcome_message')
    .toolbar('standard')
    .placeholder('sk-setting.general.welcome_message_placeholder')
    .imageUpload({ context: 'global', folderName: 'Welcome Message' });
```

### Rendering sanitized content

When you render editor output elsewhere in the admin UI, wrap it in an `sk-prose` container so the typography extensions resolve consistently:

```vue
<div class="sk-prose" v-html="welcomeMessage" />
```

Server-side, route every read through `HtmlSanitizer::clean()` before sharing to the frontend (defense-in-depth — the write path also sanitizes, but a drifted DB row or an old pre-sanitize entry must not reach the browser).

### URL scheme allowlist

`HtmlSanitizer` allows relative URLs plus `http://`, `https://`, `mailto:`, `tel:`. Everything else (`blob:`, `data:`, `file:`, `ftp:`, `javascript:`, `vbscript:`) is rejected. Keep this in mind when populating editor content programmatically — any smuggled scheme is stripped before save.

## File Upload Field API

`FB.fileUpload()` renders a picker plus a drag-and-drop drop zone. It works in both single-file and multi-file mode and can mix already-attached media with newly picked files in the same value.

- `multiple(boolean)` — allow more than one file. Default `false` (single file, value is a plain `File | null`).
- `accept(string)` — comma-separated pattern list (MIME type, wildcard like `image/*`, or extension like `.pdf`), matched client-side before a file is added. A dropped or picked file that doesn't match is silently skipped — the same behavior as the native file dialog's `accept` attribute.
- `maxFileSize(bytes)` — per-file size cap. **Enforced only when set** — omit it for no client-side size check. A file over the limit is rejected and reported in a single toast that lists the rejected file names; files under the limit are still added.
- `fileLimit(number)` — in `multiple` mode, caps the total count of existing (kept) + newly picked files. **Enforced only when set.** When a drop/pick would exceed it, only the files that fit are added and the rest are reported in the same toast as `maxFileSize` rejections.
- `existingMedia(items)` — existing media to show in edit mode (`{ id, name, url, size, mime_type }[]`).
- `existingMediaKey(key)` — key in `initialData`/`remoteData` that auto-populates `existingMedia` (e.g. `'identity_document_media'`), so you don't have to wire it manually when using `dataUrl`/`resource`.
- `deferExistingRemoval(boolean)` — see below. Default `false`.

```ts
FB.fileUpload()
    .key('attachments')
    .multiple()
    .accept('image/*,.pdf')
    .maxFileSize(5 * 1024 * 1024)
    .fileLimit(10)
    .existingMediaKey('attachments_media');
```

### Removal semantics: immediate vs. deferred

Removing an **already-saved** file has two modes:

- **Default (`deferExistingRemoval` unset/`false`)** — clicking remove fires `DELETE /media/{id}` immediately (after the confirm dialog), so the file is gone even if the form is never submitted. A failed delete leaves the file in the list and shows an error toast instead of silently dropping it from the UI.
- **`deferExistingRemoval(true)`** — nothing is deleted on click; the item only leaves the rendered list and the field's keep-list. Deletion is deferred to the save request via the field's own keep-list contract (see below).

A newly picked (not-yet-uploaded) file always removes immediately from the pending selection — there's nothing on the server to defer.

### Save-side keep-list contract (`deferExistingRemoval`)

When `deferExistingRemoval(true)` is set, the field submits an array mixing kept existing media ids and new `UploadedFile`s. Pair it with `Lvntr\StarterKit\Traits\HasMediaCollections::syncMediaCollection()` on the model, which accepts exactly that shape:

```php
// $request->validated('attachments') is an array of ids (kept) and UploadedFile instances (new)
$user->syncMediaCollection('attachments', $request->validated('attachments'));
```

`syncMediaCollection()` deletes any media in the collection whose id is **not** in the submitted keep-list and attaches every `UploadedFile` in the array — so the array the frontend sends **is** the desired end state of the collection, not a diff.

### Drag-and-drop

The drop zone accepts files dragged over it in addition to the file picker button; both paths go through the same `addFiles()` validation (`accept`, `maxFileSize`, `fileLimit`), so drag-and-drop cannot bypass a limit the picker enforces. A file that doesn't match `accept` is dropped silently, same as a picker rejection.

## Translatable Field API

Use the translatable builders when a text field should store one value per active language in a JSON column:

- `FB.translatableText()` — one `InputText` per locale.
- `FB.translatableTextarea()` — one `Textarea` per locale.
- `FB.translatableEditor()` — one rich editor per locale.

Common methods:

- `onlyLocales(['tr', 'en'])` — render only these locale codes.
- `exceptLocales(['en'])` — hide these locale codes.
- Multi-locale fields always render as tabbed locale panels (the kit's single multilingual input design).
- `localeLabelStyle('badge' | 'name' | 'flag')` — locale label rendering.

```ts
FB.form().addFields(
    FB.translatableText().key('title').label('Title').required(),
    FB.translatableTextarea().key('description').label('Description').rows(4),
    FB.translatableEditor().key('content').label('Content').minHeight('220px'),
);
```

Backend pairing:

- Store each attribute in a JSON column.
- Add Spatie `HasTranslations` to the model and list the attributes in `$translatable`.
- Use `Lvntr\StarterKit\Support\HasTranslatableRules` in FormRequests (a trait — this trait has no `App\Support` back-compat alias, so import it from the vendor namespace).
- Use `Lvntr\StarterKit\Support\TranslatableQueryHelpers` for datatable search/sort and resource output.

See [Translatable Fields](./translatable-fields.md) for the complete backend and frontend guide.

## ColorSelector Field API

`FB.colorSelector()` renders a Tailwind color palette picker with an optional tone selector.

- `colors(string[])` — available color names. Defaults to all 22 Tailwind palette families: the 17 chromatic families (`red` through `rose`) plus the 5 neutral families (`slate`, `gray`, `zinc`, `neutral`, `stone`).
- `tones(number[])` — tone steps displayed. Defaults to `[50, 100, …, 950]`.
- `format('hex' | 'name' | 'name-tone')` — output format. Defaults to `'name'`.
- `defaultTone(number)` — initial tone used when format requires one. Defaults to `500`.

Output format controls what gets stored in the model:

| `format`       | Stored value    |
| -------------- | --------------- |
| `'name'`       | `"blue"`        |
| `'name-tone'`  | `"blue-500"`    |
| `'hex'`        | `"#3b82f6"`     |

The tone selector appears below the dropdown for `'name-tone'` and `'hex'` formats. In `'name'` mode tone is ignored and the selector is hidden.

```ts
// Default — stores color name
FB.colorSelector().key('brand_color');

// Color name + tone — stores "blue-500"
FB.colorSelector().key('brand_color').format('name-tone').defaultTone(500);

// Hex value — stores "#2563eb"
FB.colorSelector().key('brand_color').format('hex').defaultTone(600);

// Restrict palette
FB.colorSelector().key('accent').colors(['red', 'blue', 'green']).tones([400, 500, 600]);
```

When the initial model value is a hex string, the component performs a reverse lookup to restore the matching color + tone selection.

## Title Icons

`FB.title()` accepts `.icon()` and `.iconPosition()` to render an icon alongside the section heading.

- `.iconPosition('left' | 'right')` — placement (default `'left'`).

```ts
FB.title('General Information').icon('pi pi-info-circle').iconPosition('left')
```

## Section / Card Grouping

`FB.section()` groups related fields into a visually distinct card block. Sections are a top-level field type rendered inside `FB.form().addFields(...)`.

```ts
import { FB } from '@lvntr/components/FormBuilder/core';

const config = FB.form()
    .layout('vertical')
    .cols(2)
    .isCard(false)  // disable form-level card; each section renders its own card
    .addFields(
        FB.section('Personal Information')
            .icon('pi pi-user')
            .cols(2)
            .addFields(
                FB.inputText().key('first_name').label('First Name'),
                FB.inputText().key('last_name').label('Last Name'),
                FB.inputText().key('email').label('Email').icon('pi pi-envelope'),
                FB.password().key('password').label('Password').icon('pi pi-lock'),
            ),
        FB.section('Address')
            .icon('pi pi-map-marker')
            .subtitle('Contact address details')
            .cols(2)
            .addFields(
                FB.inputText().key('city').label('City'),
                FB.inputText().key('postal_code').label('Postal Code'),
                FB.textarea().key('address').label('Full Address').colSpan(2), // span full row — preferred over .class('col-span-2')
            ),
        FB.section('Preferences')
            .icon('pi pi-cog')
            .isCard(false)  // transparent section — no card background, border, or shadow
            .cols(1)
            .addFields(
                FB.toggleSwitch().key('newsletter').label('Newsletter subscription'),
                FB.toggleSwitch().key('notifications').label('Notifications'),
            ),
    )
    .build();
```

**Field-level `.colSpan()` example** — using a 12-column form to mix full-width and half-width fields:

```ts
FB.form()
    .cols(12)
    .addFields(
        FB.inputText().key('title').label('Title').colSpan(12),  // full row
        FB.inputText().key('first_name').label('First Name').colSpan(6),
        FB.inputText().key('last_name').label('Last Name').colSpan(6),
        FB.textarea().key('notes').label('Notes').colSpan(12),
    )
    .build();
```

**Section Builder API:**

- `FB.section(title?)` — factory method. `title` is a translation key (optional).
- `.title(key)` — set or override the translation key used as the section heading.
- `.subtitle(key)` — secondary text rendered below the heading.
- `.icon(descriptor)` — icon shown next to the heading.
- `.iconPosition('left' | 'right')` — icon placement (default `'left'`).
- `.cols(number)` — grid columns for fields inside this section (1–12). Inherits the parent form's `cols` when omitted.
- `.isCard(boolean)` — when `false`, the section renders without a card shell (no background, shadow, or border). Defaults to `true` (card visible).
- `.addFields(...fields)` — nested field definitions. Only one level of nesting is supported.

**Notes:**

- Section `key` values do not occupy a slot in the submitted payload — the form data is flat. The example above produces: `{ first_name, last_name, email, password, city, postal_code, address, newsletter, notifications }`.
- Nested sections (section inside section) are **not supported** — single level only.
- `FB.title()` and `FB.section()` can be combined: use a `title` field outside sections for top-level headings, then group content under sections.

## Card Title Actions Slot

Both the form-level card (when `cardTitle` is set) and every `FB.section()` card expose a slot to the **right of the title** for action buttons, badges, or status indicators.

- **Form card** — slot name: `title-end`.
- **Section card** — slot name: `section-${key}-title-end`. Call `.key('your-key')` on the section so the slot name is stable; otherwise the auto-generated `__section_N` key is used.

```vue
<SkForm :config="formConfig">
  <template #title-end>
    <Button icon="pi pi-refresh" text rounded @click="refresh" />
  </template>

  <template #section-address-title-end="{ values }">
    <Tag v-if="values.is_primary" severity="success" :value="$t('forms.primary')" />
  </template>
</SkForm>
```

```ts
FB.section('Address').key('address').addFields(/* ... */)
```

The section slot scope exposes `{ values }` — a reactive snapshot of the current form values, useful for conditional rendering.

Visually, the caption block (title + subtitle) is separated from the form content with a bottom border (themed via `--p-surface-200` light / `--p-surface-700` dark) so the title, the slot content, and the subtitle read as one cohesive header above the form fields.

## Data Sources for Select-Like Fields

Select fields can get options from:

- `options([...])` for static arrays
- `definitionOptions('userStatus')` for authenticated `/definitions` records
- `optionsUrl(...)` for remote dynamic options

`enumOptions(...)` is still available as a deprecated alias for backwards compatibility, but new code should prefer `definitionOptions(...)`.

## Reactive Field Dependencies

The `visible(fn)` and `disabled(fn)` methods receive all current form values as an argument. SkForm re-evaluates them on every change, so fields can react to each other.

### Disabling a field based on another field's value

```ts
FB.form().addFields(
    FB.select()
        .key('notification_channel')
        .options([
            { label: 'Email', value: 'email' },
            { label: 'SMS', value: 'sms' },
            { label: 'None', value: 'none' },
        ]),
    FB.inputText()
        .key('notification_address')
        .disabled((values) => values.notification_channel === 'none'),
);
```

When `notification_channel` is set to `none`, the `notification_address` field becomes disabled.

### Showing/hiding a field based on another field's value

```ts
FB.toggleSwitch().key('use_custom_domain'),
FB.inputText()
    .key('custom_domain')
    .visible((values) => values.use_custom_domain === true),
```

The `custom_domain` field only appears when the toggle is enabled.

## Dynamic Options from API (Dependent Selects)

`optionsUrl` accepts either a static string or a **function** that receives current form values and returns a URL (or `null` to skip fetching). SkForm watches the returned URL — when it changes, it automatically fetches new options.

### Loading options from a static URL

```ts
FB.select().key('role').optionsUrl('/api/roles/options');
```

### Dependent select — fetching options based on another field

```ts
FB.form().addFields(
    FB.select()
        .key('country')
        .options([
            { label: 'Turkey', value: 'TR' },
            { label: 'Germany', value: 'DE' },
        ]),
    FB.select()
        .key('city')
        .optionsUrl((values) => (values.country ? `/api/cities?country=${values.country}` : null)),
);
```

How it works:

1. User selects a `country`
2. `optionsUrl` function runs with the new values, returns `/api/cities?country=TR`
3. SkForm detects the URL changed, fetches new options automatically
4. `city` dropdown is populated with the response
5. Returning `null` means "don't fetch" — the select stays empty until a country is chosen

### Combining disabled + dependent optionsUrl

```ts
FB.select()
    .key('department')
    .optionsUrl('/api/departments/options'),
FB.select()
    .key('team')
    .disabled((values) => !values.department)
    .optionsUrl((values) =>
        values.department
            ? `/api/teams/options?department=${values.department}`
            : null
    ),
```

The `team` select is disabled until a department is chosen. Once selected, teams are fetched from the API filtered by department.

## Form-Level Permission Guard

Use `.permission()` to restrict a form to users who hold a specific ability:

```ts
FB.form()
    .resource({ store: ..., update: ..., data: ..., key: 'user', id: userId })
    .permission('users.update')
    .addFields(/* ... */)
    .build();
```

The permission is resolved from the `auth.permissions` Inertia shared prop via the `useCan()` composable. When the current user lacks the permission:

- All fields are automatically `disabled` (in addition to any existing `field.disabled(values => ...)` callbacks)
- The submit button is hidden in both the top and bottom action areas
- `handleSubmit` aborts any submission as a defense-in-depth check
- Cancel/back buttons and custom slot actions still render normally

## Best Practice

Keep field definitions close to the page or tab that owns the form. Use domain Actions and Form Requests on the backend so the form stays a rendering layer, not a business layer.

## Works Well For

- settings tabs
- create and edit resource forms
- profile forms
- admin tools with repeated field patterns

## Built-in Behavior

`SkForm` already handles:

- loading initial data from `dataUrl`
- preloading definition options
- updating dynamic select options when dependent fields change
- rendering hidden fields as native `<input type="hidden">` elements
- file upload submission through `forceFormData`
- dialog-friendly cancel behavior
- unified error rendering for internal or external mode
- turning the form read-only when `permission` is set and the user lacks the ability

### Exposed component API

`SkForm` exposes a small imperative surface via `defineExpose` for hosts that hold a template ref on it:

- `reset()` — resets the internal Inertia form and clears errors (internal submit mode only).
- `submit()` — programmatically triggers the same submit path the action bar's submit button uses. Useful when `hideActions(true)` moves the submit control into a host-rendered footer.
- `reload()` — re-fetches `dataUrl` on demand, with the same loading-state and error-toast semantics as the mount-time load. No-op when the form has no `dataUrl`. Use this instead of `.reloadOnDataUrlChange()` when you want an explicit refresh trigger (a "Refresh" button, a sibling save event) rather than an automatic refetch whenever the URL prop changes.
- `processing`, `isDirty`, `dataLoading`, `remoteData`, `currentValues` — reactive state mirrors for a host-rendered action bar or status indicator.
- `setValue(key, value)` — programmatically sets a single field's value.
