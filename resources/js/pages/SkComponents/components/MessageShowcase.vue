<script setup lang="ts">
    import SkCard from '@lvntr/components/ui/SkCard.vue';
    import { trans } from 'laravel-vue-i18n';

    // Built-in PrimeVue severities, repainted to the SK palette in the theme CSS.
    // The icon mirrors the design's banner motifs; title/desc are translated.
    const severities = [
        { sev: 'success', icon: 'pi pi-check-circle' },
        { sev: 'info', icon: 'pi pi-info-circle' },
        { sev: 'warn', icon: 'pi pi-exclamation-triangle' },
        { sev: 'danger', icon: 'pi pi-times-circle' },
        { sev: 'secondary', icon: 'pi pi-bookmark' },
        { sev: 'contrast', icon: 'pi pi-bell' },
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
    const k = (sev: string, field: 'title' | 'desc'): string => `sk-component.message.items.${sev}.${field}`;
</script>

<template>
    <SkCard>
        <template #title>{{ $t('sk-component.message.title') }}</template>
        <template #subtitle>{{ $t('sk-component.message.subtitle') }}</template>
        <template #actions>
            <a href="https://primevue.org/message/" target="_blank" rel="noopener noreferrer">
                <Button :label="$t('sk-component.docs')" icon="pi pi-book" outlined size="small" />
            </a>
        </template>
        <template #content>
        <Message severity="info" :closable="false" class="mb-6">
            <span class="text-[13.5px] leading-relaxed">{{ trans('sk-component.message.intro') }}</span>
        </Message>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <!-- 01 · Filled (solid banner) -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">01</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.message.sections.filled.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.message.sections.filled.desc') }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                    <Message v-for="m in severities" :key="'f-' + m.sev" :severity="m.sev" :icon="m.icon" class="p-message-fill">
                        <div class="font-semibold">{{ $t(k(m.sev, 'title')) }}</div>
                        <div class="text-[12.5px] opacity-80">{{ $t(k(m.sev, 'desc')) }}</div>
                    </Message>
                </div>
            </SkCard>

            <!-- 02 · Accent (default variant) -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">02</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.message.sections.accent.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.message.sections.accent.desc') }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                    <Message v-for="m in severities.slice(0, 4)" :key="'a-' + m.sev" :severity="m.sev" :icon="m.icon" :closable="false">
                        <div class="font-semibold">{{ $t(k(m.sev, 'title')) }}</div>
                        <div class="text-[12.5px] opacity-70">{{ $t(k(m.sev, 'desc')) }}</div>
                    </Message>
                </div>
            </SkCard>

            <!-- 03 · Outlined variant -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">03</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.message.sections.outlined.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.message.sections.outlined.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3">
                    <Message v-for="m in severities.slice(0, 4)" :key="'o-' + m.sev" :severity="m.sev" :icon="m.icon" variant="outlined" :closable="false">
                        {{ $t(k(m.sev, 'title')) }}
                    </Message>
                </div>
            </SkCard>

            <!-- 04 · Simple variant -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">04</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.message.sections.simple.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.message.sections.simple.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-2.5">
                    <Message v-for="m in severities.slice(0, 4)" :key="'s-' + m.sev" :severity="m.sev" :icon="m.icon" variant="simple" :closable="false">
                        {{ $t(k(m.sev, 'title')) }}
                    </Message>
                </div>
            </SkCard>

            <!-- 05 · InlineMessage -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">05</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.message.sections.inline.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.message.sections.inline.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <InlineMessage v-for="m in severities" :key="'i-' + m.sev" :severity="m.sev" :icon="m.icon">
                        {{ $t(k(m.sev, 'title')) }}
                    </InlineMessage>
                </div>
            </SkCard>

            <!-- 06 · All Tailwind colors (filled) -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">06</span>
                    <div class="flex-1">
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.message.sections.colors.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.message.sections.colors.desc', { count: String(colors.length) }) }}</p>
                    </div>
                    <span class="inline-flex h-8 shrink-0 items-center gap-2 rounded-md border border-surface-200 px-2.5 font-mono text-[11px] text-surface-500 dark:border-surface-700 dark:text-surface-400">
                        <i class="pi pi-palette text-[12px]" />{{ $t('sk-component.message.sections.colors.badge', { count: String(colors.length) }) }}
                    </span>
                </div>
                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                    <Message v-for="c in colors" :key="'tw-' + c" :severity="c" icon="pi pi-tag" class="p-message-fill">
                        <div class="font-semibold">{{ cap(c) }}</div>
                        <div class="font-mono text-[11.5px] opacity-80">severity="{{ c }}"</div>
                    </Message>
                </div>
            </SkCard>
        </div>
        </template>
    </SkCard>
</template>
