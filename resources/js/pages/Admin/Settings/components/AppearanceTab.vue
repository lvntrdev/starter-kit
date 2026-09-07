<script setup lang="ts">
    import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
    import { router } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import SkCard from '@lvntr/components/ui/SkCard.vue';
    import {
        ACCENT_COLORS,
        ACCENT_SWATCH,
        useAccentColor,
        type AccentColor,
    } from '@/composables/useAccentColor';

    interface AppearanceSettings {
        theme: string;
        available_themes: string[];
        runtime_themes?: string[];
        accent_color: string;
        dark_mode_default: boolean;
        sidebar_style: string;
        logo_light_url: string | null;
        logo_dark_url: string | null;
        favicon_url: string | null;
    }

    interface Props {
        settings: AppearanceSettings;
    }

    const props = defineProps<Props>();

    // applyAccent drives the live preview; `accent` is the persisted per-user
    // override we DON'T touch here — this tab edits the global default only,
    // so we keep a local restore value to undo the preview on cancel/leave.
    const { applyAccent, accent: userAccent } = useAccentColor();

    // ── Editable form state (the saved appearance defaults) ──────────────
    const form = reactive({
        theme: props.settings.theme,
        accent_color: props.settings.accent_color as AccentColor,
        dark_mode_default: props.settings.dark_mode_default,
        sidebar_style: props.settings.sidebar_style,
    });

    const saving = ref(false);

    // The accent the user's own session was showing before we previewed, so we
    // can restore it if they navigate away without saving (preview is global).
    const restoreAccent = userAccent.value;

    // ── Dirty tracking ───────────────────────────────────────────────────
    const isDirty = computed(
        () =>
            form.theme !== props.settings.theme ||
            form.accent_color !== props.settings.accent_color ||
            form.dark_mode_default !== props.settings.dark_mode_default ||
            form.sidebar_style !== props.settings.sidebar_style,
    );

    // ── Theme cards ──────────────────────────────────────────────────────
    function selectTheme(theme: string): void {
        form.theme = theme;
    }

    // Per-theme description (falls back to the section subtitle for unmapped themes).
    function themeDesc(theme: string): string {
        const key = `sk-setting.appearance.theme_desc.${theme}`;
        const label = trans(key);
        return label === key ? trans('sk-setting.appearance.theme_section_subtitle') : label;
    }

    // Returns true when the theme is a runtime (instant) kit theme.
    // Falls back to ['main', 'aura'] if the payload field is absent.
    function isRuntimeTheme(theme: string): boolean {
        const runtimeThemes = props.settings.runtime_themes ?? ['main', 'aura'];
        return runtimeThemes.includes(theme);
    }

    // ── Accent swatches ──────────────────────────────────────────────────
    // Full palette, mirroring the header popover: `Varsayılan` (default) plus
    // every accent (greys, colours and the four custom muted tones), shown as
    // chip + label cards.
    const swatchColors = ACCENT_COLORS;

    function selectAccent(color: AccentColor): void {
        form.accent_color = color;
    }

    // Live preview: whenever the picked accent changes, apply it LITERALLY so the
    // admin previews the exact system default they're setting — here `'default'`
    // is kit-blue (they are DEFINING the default), not "follow global". The admin's
    // own session accent (restoreAccent) is reinstated on leave so the preview
    // never sticks across the app for an unsaved change.
    watch(
        () => form.accent_color,
        (val) => applyAccent(val, { followGlobal: false }),
    );

    onBeforeUnmount(() => {
        // Reinstate the admin's actual view (their per-user accent, which follows
        // the global default when unset) so a previewed-but-unsaved default doesn't
        // linger in their session.
        applyAccent(restoreAccent);
    });

    // ── Save ─────────────────────────────────────────────────────────────
    function save(): void {
        router.put('/settings/appearance', { ...form }, {
            preserveScroll: true,
            onStart: () => {
                saving.value = true;
            },
            onFinish: () => {
                saving.value = false;
            },
        });
    }
</script>

<template>
    <!-- Single card: header · grouped rows (label left / content right) · footer.
         SkCard rather than a hand-rolled surface so aura can host the page header
         in its head like every other admin screen (see `ui/pageHeader.ts`). -->
    <SkCard
        flush
        :title="$t('sk-setting.appearance.title')"
        :subtitle="$t('sk-setting.appearance.subtitle')"
    >
        <!-- Card body: settings groups, divided -->
        <div class="divide-y divide-surface-200 px-6 dark:divide-surface-700">
            <!-- ── Logo ─────────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-x-11 gap-y-6 py-[26px] md:grid-cols-[216px_1fr]">
                <div>
                    <div class="text-sm font-bold tracking-tight text-surface-900 dark:text-surface-100">
                        {{ $t('sk-setting.appearance.logo_section_title') }}
                    </div>
                    <div class="mt-[7px] text-xs leading-relaxed text-surface-500 dark:text-surface-400">
                        {{ $t('sk-setting.appearance.logo_section_subtitle') }}
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <SkImageUpload
                        layout="stacked"
                        variant="logo-light"
                        :preview-url="props.settings.logo_light_url"
                        upload-url="/settings/appearance/logo/light"
                        field-name="logo"
                        response-key="logo_url"
                        accept="image/png,image/jpeg,image/webp"
                        :label="$t('sk-setting.appearance.logo_light_label')"
                        :hint="$t('sk-setting.appearance.logo_light_hint')"
                        :upload-label="$t('sk-setting.appearance.upload_label')"
                        :remove-label="$t('sk-setting.appearance.remove_label')"
                        :remove-confirm="$t('sk-setting.appearance.logo_remove_confirm')"
                    />
                    <SkImageUpload
                        layout="stacked"
                        variant="logo-dark"
                        :preview-url="props.settings.logo_dark_url"
                        upload-url="/settings/appearance/logo/dark"
                        field-name="logo"
                        response-key="logo_url"
                        accept="image/png,image/jpeg,image/webp"
                        :label="$t('sk-setting.appearance.logo_dark_label')"
                        :hint="$t('sk-setting.appearance.logo_dark_hint')"
                        :upload-label="$t('sk-setting.appearance.upload_label')"
                        :remove-label="$t('sk-setting.appearance.remove_label')"
                        :remove-confirm="$t('sk-setting.appearance.logo_remove_confirm')"
                    />
                </div>
            </div>

            <!-- ── Favicon ──────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-x-11 gap-y-6 py-[26px] md:grid-cols-[216px_1fr]">
                <div>
                    <div class="text-sm font-bold tracking-tight text-surface-900 dark:text-surface-100">
                        {{ $t('sk-setting.appearance.favicon_section_title') }}
                    </div>
                    <div class="mt-[7px] text-xs leading-relaxed text-surface-500 dark:text-surface-400">
                        {{ $t('sk-setting.appearance.favicon_section_subtitle') }}
                    </div>
                </div>

                <SkImageUpload
                    layout="row"
                    variant="favicon"
                    :preview-url="props.settings.favicon_url"
                    upload-url="/settings/appearance/favicon"
                    field-name="favicon"
                    response-key="favicon_url"
                    accept="image/png,image/x-icon,.ico"
                    :label="$t('sk-setting.appearance.favicon_label')"
                    :hint="$t('sk-setting.appearance.favicon_hint')"
                    :upload-label="$t('sk-setting.appearance.upload_label')"
                    :remove-label="$t('sk-setting.appearance.remove_label')"
                    :remove-confirm="$t('sk-setting.appearance.favicon_remove_confirm')"
                />
            </div>

            <!-- ── Tema ─────────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-x-11 gap-y-6 py-[26px] md:grid-cols-[216px_1fr]">
                <div>
                    <div class="text-sm font-bold tracking-tight text-surface-900 dark:text-surface-100">
                        {{ $t('sk-setting.appearance.theme_section_title') }}
                    </div>
                    <div class="mt-[7px] text-xs leading-relaxed text-surface-500 dark:text-surface-400">
                        {{ $t('sk-setting.appearance.theme_section_subtitle') }}
                    </div>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button
                        v-for="theme in props.settings.available_themes"
                        :key="theme"
                        type="button"
                        class="relative flex w-[280px] max-w-full items-center gap-3 rounded-md border border-surface-200 py-3 pl-3 pr-4 text-left transition-colors dark:border-surface-700"
                        :class="
                            form.theme === theme
                                ? 'border-primary-500! bg-primary-500/5 ring-[3px] ring-primary-500/15'
                                : 'hover:border-surface-300 dark:hover:border-surface-600'
                        "
                        @click="selectTheme(theme)"
                    >
                        <span class="flex h-[38px] w-12 shrink-0 overflow-hidden rounded-md border border-surface-200 dark:border-surface-700">
                            <span
                                class="h-full w-[15px] shrink-0"
                                :class="theme === 'main' ? 'bg-surface-300 dark:bg-surface-600' : 'bg-primary-500'"
                            />
                            <span class="h-full flex-1 bg-surface-50 dark:bg-surface-800" />
                        </span>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span
                                class="text-sm font-bold capitalize"
                                :class="form.theme === theme ? 'text-primary-500' : 'text-surface-900 dark:text-surface-100'"
                            >
                                {{ theme }}
                            </span>
                            <span class="text-[11.5px] text-surface-500 dark:text-surface-400">
                                {{ themeDesc(theme) }}
                            </span>
                            <span
                                v-if="!isRuntimeTheme(theme)"
                                class="mt-0.5 text-[11px] text-amber-600 dark:text-amber-400"
                            >
                                {{ $t('sk-setting.appearance.theme_hint') }}
                            </span>
                        </span>
                        <span
                            class="ml-auto grid h-5 w-5 shrink-0 place-items-center rounded-full border transition-colors"
                            :class="
                                form.theme === theme
                                    ? 'border-primary-500 bg-primary-500'
                                    : 'border-surface-300 dark:border-surface-600'
                            "
                        >
                            <i v-show="form.theme === theme" class="pi pi-check text-[10px] text-white" />
                        </span>
                    </button>
                </div>
            </div>

            <!-- ── Varsayılan Renk ──────────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-x-11 gap-y-6 py-[26px] md:grid-cols-[216px_1fr]">
                <div>
                    <div class="text-sm font-bold tracking-tight text-surface-900 dark:text-surface-100">
                        {{ $t('sk-setting.appearance.accent_section_title') }}
                    </div>
                    <div class="mt-[7px] text-xs leading-relaxed text-surface-500 dark:text-surface-400">
                        {{ $t('sk-setting.appearance.accent_section_subtitle') }}
                    </div>
                </div>

                <div class="grid grid-cols-[repeat(auto-fill,minmax(82px,1fr))] gap-2.5">
                    <!-- Varsayılan (default = kit primary) -->
                    <button
                        type="button"
                        class="flex flex-col gap-2 rounded-md border p-[9px] transition-colors"
                        :class="
                            form.accent_color === 'default'
                                ? 'border-primary-500! bg-primary-500/5 ring-[3px] ring-primary-500/15'
                                : 'border-surface-200 hover:border-surface-300 dark:border-surface-700 dark:hover:border-surface-600'
                        "
                        :title="$t('sk-layout.color_default')"
                        :aria-label="$t('sk-layout.color_default')"
                        @click="selectAccent('default')"
                    >
                        <span class="grid h-[34px] place-items-center rounded-md border border-dashed border-surface-300 text-surface-400 dark:border-surface-600">
                            <i class="pi pi-ban text-[13px]" />
                        </span>
                        <span
                            class="text-center text-[11px] font-semibold"
                            :class="form.accent_color === 'default' ? 'text-surface-900 dark:text-surface-100' : 'text-surface-500 dark:text-surface-400'"
                        >
                            {{ $t('sk-layout.color_default') }}
                        </span>
                    </button>

                    <button
                        v-for="color in swatchColors"
                        :key="color"
                        type="button"
                        class="flex flex-col gap-2 rounded-md border p-[9px] transition-colors"
                        :class="
                            form.accent_color === color
                                ? 'border-primary-500! bg-primary-500/5 ring-[3px] ring-primary-500/15'
                                : 'border-surface-200 hover:border-surface-300 dark:border-surface-700 dark:hover:border-surface-600'
                        "
                        :title="color"
                        :aria-label="color"
                        @click="selectAccent(color)"
                    >
                        <span
                            class="grid h-[34px] place-items-center rounded-md"
                            :style="{ background: ACCENT_SWATCH[color] }"
                        >
                            <i
                                v-show="form.accent_color === color"
                                class="pi pi-check text-[13px] text-white [text-shadow:0_1px_2px_rgba(0,0,0,.3)]"
                            />
                        </span>
                        <span
                            class="text-center text-[11px] font-semibold capitalize"
                            :class="form.accent_color === color ? 'text-surface-900 dark:text-surface-100' : 'text-surface-500 dark:text-surface-400'"
                        >
                            {{ color }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- ── Arayüz Varsayılanları ────────────────────────────────── -->
            <div class="grid grid-cols-1 gap-x-11 gap-y-6 py-[26px] md:grid-cols-[216px_1fr]">
                <div>
                    <div class="text-sm font-bold tracking-tight text-surface-900 dark:text-surface-100">
                        {{ $t('sk-setting.appearance.interface_section_title') }}
                    </div>
                    <div class="mt-[7px] text-xs leading-relaxed text-surface-500 dark:text-surface-400">
                        {{ $t('sk-setting.appearance.interface_section_subtitle') }}
                    </div>
                </div>

                <div class="flex flex-col gap-2.5">
                    <!-- Koyu mod -->
                    <div class="flex items-center gap-3.5 rounded-md border border-surface-200 bg-surface-0 px-4 py-3.5 dark:border-surface-700 dark:bg-surface-900">
                        <span class="grid size-9 shrink-0 place-items-center rounded-md bg-primary-500/10 text-primary-500">
                            <i class="pi pi-moon text-[15px]" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13.5px] font-bold text-surface-900 dark:text-surface-100">
                                {{ $t('sk-setting.appearance.dark_mode_label') }}
                            </div>
                            <div class="mt-0.5 text-xs text-surface-500 dark:text-surface-400">
                                {{ $t('sk-setting.appearance.dark_mode_hint') }}
                            </div>
                        </div>
                        <ToggleSwitch v-model="form.dark_mode_default" />
                    </div>

                    <!-- Yan menü stili -->
                    <div class="flex items-center gap-3.5 rounded-md border border-surface-200 bg-surface-0 px-4 py-3.5 dark:border-surface-700 dark:bg-surface-900">
                        <span class="grid size-9 shrink-0 place-items-center rounded-md bg-primary-500/10 text-primary-500">
                            <i class="pi pi-objects-column text-[15px]" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13.5px] font-bold text-surface-900 dark:text-surface-100">
                                {{ $t('sk-setting.appearance.sidebar_section_title') }}
                            </div>
                            <div class="mt-0.5 text-xs text-surface-500 dark:text-surface-400">
                                {{ $t('sk-setting.appearance.sidebar_section_subtitle') }}
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2.5">
                            <button
                                v-for="opt in [
                                    { value: 'colored', label: $t('sk-setting.appearance.sidebar_colored_label') },
                                    { value: 'light', label: $t('sk-setting.appearance.sidebar_light_label') },
                                ]"
                                :key="opt.value"
                                type="button"
                                class="flex items-center gap-2.5 rounded-md border px-3.5 py-2 transition-colors"
                                :class="
                                    form.sidebar_style === opt.value
                                        ? 'border-primary-500! bg-primary-500/8'
                                        : 'border-surface-200 hover:border-surface-300 dark:border-surface-700 dark:hover:border-surface-600'
                                "
                                @click="form.sidebar_style = opt.value"
                            >
                                <span
                                    class="grid size-[18px] shrink-0 place-items-center rounded-[5px] border transition-colors"
                                    :class="
                                        form.sidebar_style === opt.value
                                            ? 'border-primary-500! bg-primary-500'
                                            : 'border-surface-300 dark:border-surface-600'
                                    "
                                >
                                    <i v-show="form.sidebar_style === opt.value" class="pi pi-check text-[9px] text-white" />
                                </span>
                                <span
                                    class="text-[13.5px] font-medium"
                                    :class="form.sidebar_style === opt.value ? 'text-surface-900 dark:text-surface-100' : 'text-surface-600 dark:text-surface-300'"
                                >
                                    {{ opt.label }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Card footer: unsaved hint + save -->
        <template #footer>
            <small v-if="isDirty" class="mr-auto flex items-center gap-1.5 text-amber-600 dark:text-amber-400">
                <i class="pi pi-exclamation-circle text-xs" />
                {{ $t('sk-setting.appearance.unsaved') }}
            </small>
            <span v-else class="mr-auto" />
            <Button
                type="button"
                :label="$t('sk-button.update')"
                icon="pi pi-save"
                :loading="saving"
                :disabled="!isDirty"
                @click="save"
            />
        </template>
    </SkCard>
</template>
