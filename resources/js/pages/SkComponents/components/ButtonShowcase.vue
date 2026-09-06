<script setup lang="ts">
    import SkCard from '@lvntr/components/ui/SkCard.vue';
    import { trans } from 'laravel-vue-i18n';

    // Built-in PrimeVue severities — kept as PrimeVue's own styling (the theme
    // only ADDS the Tailwind families, it does not repaint these). The `label`
    // is the severity API name itself, so it stays literal (not translated).
    const severities = [
        { sev: undefined, label: 'Primary' },
        { sev: 'secondary', label: 'Secondary' },
        { sev: 'success', label: 'Success' },
        { sev: 'info', label: 'Info' },
        { sev: 'warn', label: 'Warn' },
        { sev: 'help', label: 'Help' },
        { sev: 'danger', label: 'Danger' },
        { sev: 'contrast', label: 'Contrast' },
    ] as const;

    // Every Tailwind family (+ the custom mauve / olive / mist / taupe) usable
    // directly as a `severity` thanks to the `[data-p-severity]` theme rules.
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
        <template #title>{{ $t('sk-component.button.title') }}</template>
        <template #subtitle>{{ $t('sk-component.button.subtitle') }}</template>
        <template #actions>
            <a href="https://primevue.org/button/" target="_blank" rel="noopener noreferrer">
                <Button :label="$t('sk-component.docs')" icon="pi pi-book" outlined size="small" />
            </a>
        </template>
        <template #content>
        <Message severity="info" :closable="false" class="mb-6">
            <span class="text-[13.5px] leading-relaxed">{{ trans('sk-component.button.intro') }}</span>
        </Message>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <!-- 01 · PrimeVue native severities -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">01</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.button.sections.severities.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.button.sections.severities.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button v-for="s in severities" :key="'f-' + s.label" :severity="s.sev" :label="s.label" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button v-for="s in severities" :key="'o-' + s.label" :severity="s.sev" :label="s.label" outlined />
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button v-for="s in severities" :key="'t-' + s.label" :severity="s.sev" :label="s.label" text />
                    </div>
                </div>
            </SkCard>

            <!-- 02 · Variants -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">02</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.button.sections.variants.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.button.sections.variants.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3.5">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button :label="$t('sk-component.button.actions.save')" icon="pi pi-check" />
                        <Button :label="$t('sk-component.button.actions.next')" icon="pi pi-arrow-right" icon-pos="right" />
                        <Button :label="$t('sk-component.button.actions.delete')" icon="pi pi-trash" severity="danger" outlined />
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button label="Pill" rounded />
                        <Button label="Pill" severity="secondary" rounded outlined />
                        <Button icon="pi pi-heart" severity="danger" rounded :aria-label="$t('sk-component.button.aria.like')" />
                        <Button icon="pi pi-check" :aria-label="$t('sk-component.button.aria.confirm')" />
                        <Button icon="pi pi-cog" severity="secondary" outlined :aria-label="$t('sk-component.button.aria.settings')" />
                        <Button icon="pi pi-bookmark" text :aria-label="$t('sk-component.button.aria.bookmark')" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button :label="$t('sk-component.sizes.small')" icon="pi pi-check" size="small" />
                        <Button :label="$t('sk-component.sizes.normal')" icon="pi pi-check" />
                        <Button :label="$t('sk-component.sizes.large')" icon="pi pi-check" size="large" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Button :label="$t('sk-component.button.actions.loading')" loading />
                        <Button :label="$t('sk-component.button.actions.disabled')" disabled />
                    </div>
                </div>
            </SkCard>

            <!-- 03 · Per-color full variant set -->
            <SkCard v-for="c in colors" :key="c">
                <div class="mb-4 flex items-center gap-2.5 border-b border-surface-200 pb-3 dark:border-surface-700">
                    <!-- Decorative swatch — a tiny filled Button reuses the exact themed
                         color (var(--btn-c)) so it never drifts from the palette. -->
                    <Button :severity="c" rounded aria-hidden="true" tabindex="-1"
                            class="pointer-events-none h-3.5 w-3.5 min-w-0 shrink-0 p-0" />
                    <span class="font-mono text-[12.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ cap(c) }}</span>
                    <span class="ml-auto font-mono text-[10.5px] text-surface-400 dark:text-surface-500">severity="{{ c }}"</span>
                </div>

                <div class="flex flex-col gap-3.5">
                    <!-- Filled -->
                    <div class="flex flex-col gap-1.5">
                        <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-surface-400">{{ $t('sk-component.variants.filled') }}</span>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <Button :severity="c" :label="cap(c)" />
                            <Button :severity="c" :label="cap(c)" icon="pi pi-check" />
                            <Button :severity="c" :label="cap(c)" disabled />
                        </div>
                    </div>
                    <!-- Raised / Rounded -->
                    <div class="flex flex-col gap-1.5">
                        <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-surface-400">{{ $t('sk-component.variants.raised_rounded') }}</span>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <Button :severity="c" :label="cap(c)" raised />
                            <Button :severity="c" label="Pill" rounded />
                            <Button :severity="c" label="Pill" rounded outlined />
                        </div>
                    </div>
                    <!-- Outlined / Text -->
                    <div class="flex flex-col gap-1.5">
                        <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-surface-400">{{ $t('sk-component.variants.outlined_text') }}</span>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <Button :severity="c" :label="$t('sk-component.variants.outlined')" outlined />
                            <Button :severity="c" :label="$t('sk-component.variants.outlined')" icon="pi pi-arrow-right" icon-pos="right" outlined />
                            <Button :severity="c" :label="$t('sk-component.variants.text')" text />
                        </div>
                    </div>
                    <!-- Icon only -->
                    <div class="flex flex-col gap-1.5">
                        <span class="font-mono text-[10px] uppercase tracking-[0.16em] text-surface-400">{{ $t('sk-component.variants.icon_only') }}</span>
                        <div class="flex flex-wrap items-center gap-2.5">
                            <Button :severity="c" icon="pi pi-check" :aria-label="c" />
                            <Button :severity="c" icon="pi pi-star" rounded :aria-label="c" />
                            <Button :severity="c" icon="pi pi-heart" rounded outlined :aria-label="c" />
                            <Button :severity="c" icon="pi pi-cog" text :aria-label="c" />
                        </div>
                    </div>
                </div>
            </SkCard>
        </div>
        </template>
    </SkCard>
</template>
