<script setup lang="ts">
    import SkCard from '@lvntr/components/ui/SkCard.vue';
    import { trans } from 'laravel-vue-i18n';

    // Built-in PrimeVue severities, repainted to the SK palette in the theme CSS.
    // The `label` is the severity API name itself, so it stays literal.
    const severities = [
        { sev: 'secondary', label: 'Secondary', icon: 'pi pi-bookmark' },
        { sev: 'success', label: 'Success', icon: 'pi pi-check' },
        { sev: 'info', label: 'Info', icon: 'pi pi-info-circle' },
        { sev: 'warn', label: 'Warn', icon: 'pi pi-exclamation-triangle' },
        { sev: 'danger', label: 'Danger', icon: 'pi pi-times-circle' },
        { sev: 'contrast', label: 'Contrast', icon: 'pi pi-bolt' },
    ] as const;

    // Every Tailwind family (+ the custom mauve / olive / mist / taupe) usable
    // directly as a `severity` thanks to the `[data-p]` theme rules.
    const colors = [
        'slate', 'gray', 'zinc', 'neutral', 'stone',
        'mauve', 'olive', 'mist', 'taupe',
        'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
        'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ] as const;

    const cap = (s: string): string => s.charAt(0).toUpperCase() + s.slice(1);
</script>

<template>
    <SkCard>
        <template #title>{{ $t('sk-component.tag.title') }}</template>
        <template #subtitle>{{ $t('sk-component.tag.subtitle') }}</template>
        <template #actions>
            <a href="https://primevue.org/tag/" target="_blank" rel="noopener noreferrer">
                <Button :label="$t('sk-component.docs')" icon="pi pi-book" outlined size="small" />
            </a>
        </template>
        <template #content>
        <Message severity="info" :closable="false" class="mb-6">
            <span class="text-[13.5px] leading-relaxed">{{ trans('sk-component.tag.intro') }}</span>
        </Message>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <!-- 01 · Filled (default) -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">01</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.tag.sections.filled.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.tag.sections.filled.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Tag v-for="t in severities" :key="t.sev" :severity="t.sev" :value="t.label" />
                </div>
            </SkCard>

            <!-- 02 · Rounded -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">02</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.tag.sections.rounded.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.tag.sections.rounded.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Tag v-for="t in severities" :key="t.sev" :severity="t.sev" :value="t.label" rounded />
                </div>
            </SkCard>

            <!-- 03 · With icon -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">03</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.tag.sections.icon.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.tag.sections.icon.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Tag v-for="t in severities" :key="t.sev" :severity="t.sev" :value="t.label" :icon="t.icon" />
                </div>
            </SkCard>

            <!-- 04 · Soft / tonal -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">04</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.tag.sections.soft.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.tag.sections.soft.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Tag v-for="t in severities" :key="t.sev" :severity="t.sev" :value="t.label" rounded class="p-tag-soft p-tag-dot" />
                </div>
            </SkCard>

            <!-- 05 · Outlined -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">05</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.tag.sections.outlined.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.tag.sections.outlined.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <Tag v-for="t in severities" :key="t.sev" :severity="t.sev" :value="t.label" class="p-tag-outlined" />
                </div>
            </SkCard>

            <!-- 06 · Removable + sizes -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">06</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.tag.sections.removable.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.tag.sections.removable.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3.5">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Tag severity="info" rounded class="p-tag-soft">
                            <span>{{ $t('sk-component.tag.states.active') }}</span>
                            <button type="button" class="p-tag-x" :aria-label="$t('sk-component.tag.remove')"><i class="pi pi-times" /></button>
                        </Tag>
                        <Tag severity="success" rounded class="p-tag-soft">
                            <span>{{ $t('sk-component.tag.states.approved') }}</span>
                            <button type="button" class="p-tag-x" :aria-label="$t('sk-component.tag.remove')"><i class="pi pi-times" /></button>
                        </Tag>
                        <Tag severity="danger" rounded>
                            <span>{{ $t('sk-component.tag.states.urgent') }}</span>
                            <button type="button" class="p-tag-x" :aria-label="$t('sk-component.tag.remove')"><i class="pi pi-times" /></button>
                        </Tag>
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Tag severity="info" :value="$t('sk-component.sizes.small')" class="p-tag-sm" />
                        <Tag severity="info" :value="$t('sk-component.sizes.normal')" />
                        <Tag severity="info" :value="$t('sk-component.sizes.large')" class="p-tag-lg" />
                    </div>
                </div>
            </SkCard>

            <!-- 07 · All Tailwind colors -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">07</span>
                    <div class="flex-1">
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.tag.sections.colors.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.tag.sections.colors.desc', { count: String(colors.length) }) }}</p>
                    </div>
                    <span class="inline-flex h-8 shrink-0 items-center gap-2 rounded-md border border-surface-200 px-2.5 font-mono text-[11px] text-surface-500 dark:border-surface-700 dark:text-surface-400">
                        <i class="pi pi-palette text-[12px]" />{{ $t('sk-component.tag.sections.colors.badge', { count: String(colors.length) }) }}
                    </span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-surface-400">{{ $t('sk-component.variants.filled') }}</span>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Tag v-for="c in colors" :key="'f-' + c" :severity="c" :value="cap(c)" />
                    </div>
                </div>
                <div class="mt-4 flex flex-col gap-1.5">
                    <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-surface-400">{{ $t('sk-component.variants.soft') }}</span>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Tag v-for="c in colors" :key="'s-' + c" :severity="c" :value="cap(c)" rounded class="p-tag-soft p-tag-dot" />
                    </div>
                </div>
                <div class="mt-4 flex flex-col gap-1.5">
                    <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-surface-400">{{ $t('sk-component.variants.outlined') }}</span>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Tag v-for="c in colors" :key="'o-' + c" :severity="c" :value="cap(c)" class="p-tag-outlined" />
                    </div>
                </div>
            </SkCard>
        </div>
        </template>
    </SkCard>
</template>
