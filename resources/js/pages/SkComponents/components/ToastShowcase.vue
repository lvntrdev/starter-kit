<script setup lang="ts">
    import { useToast } from 'primevue/usetoast';
    import type { ToastMessageOptions } from 'primevue/toast';
    import SkCard from '@lvntr/components/ui/SkCard.vue';
    import { trans } from 'laravel-vue-i18n';

    // Toasts are overlay-only, so the visual catalog below renders the SAME
    // `.sk-toast` markup the runtime ToastComponent emits (theme: components/
    // toast.css) — guaranteed identical to a live toast. The final section fires
    // real toasts through useToast() to prove the runtime integration.

    const toast = useToast();

    // The runtime ToastComponent reads a few extra message fields (icon / actions)
    // that aren't on PrimeVue's ToastMessageOptions; this typed wrapper carries
    // them through without tripping the excess-property check on add().
    interface SkToastAction {
        label: string;
        command?: () => void;
        primary?: boolean;
        dismiss?: boolean;
    }
    interface SkToastOptions extends ToastMessageOptions {
        icon?: string;
        actions?: SkToastAction[];
    }
    const addToast = (options: SkToastOptions): void => toast.add(options);

    // Built-in PrimeVue severities, repainted to the SK palette in the theme CSS.
    const severities = [
        { sev: 'success', icon: 'pi pi-check-circle' },
        { sev: 'info', icon: 'pi pi-info-circle' },
        { sev: 'warn', icon: 'pi pi-exclamation-triangle' },
        { sev: 'error', icon: 'pi pi-times-circle' },
        { sev: 'secondary', icon: 'pi pi-bookmark' },
        { sev: 'contrast', icon: 'pi pi-bell' },
    ] as const;

    // Every Tailwind family (+ the custom mauve / olive / mist / taupe) usable
    // directly as a `severity` thanks to the `sk-toast-<name>` theme rules.
    const colors = [
        'slate', 'gray', 'zinc', 'neutral', 'stone',
        'mauve', 'olive', 'mist', 'taupe',
        'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald', 'teal',
        'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ] as const;

    const cap = (s: string): string => s.charAt(0).toUpperCase() + s.slice(1);
    const k = (sev: string, field: 'summary' | 'detail'): string => `sk-component.toast.items.${sev}.${field}`;

    // ── Live triggers — fire real toasts through the runtime ToastComponent ──
    const fire = (sev: string, icon: string, styleClass?: string): void => {
        addToast({
            severity: sev,
            summary: trans(k(sev, 'summary')),
            detail: trans(k(sev, 'detail')),
            icon,
            styleClass,
            group: 'bc',
            life: 4000,
        });
    };

    const fireDelete = (): void => {
        addToast({
            severity: 'warn',
            summary: trans('sk-component.toast.sticky.delete.summary'),
            detail: trans('sk-component.toast.sticky.delete.detail'),
            icon: 'pi pi-exclamation-triangle',
            group: 'bc',
            actions: [
                { label: trans('sk-component.toast.sticky.delete.confirm'), primary: true },
                { label: trans('sk-component.toast.sticky.delete.cancel') },
            ],
        });
    };

    const fireUpdate = (): void => {
        addToast({
            severity: 'info',
            summary: trans('sk-component.toast.sticky.update.summary'),
            detail: trans('sk-component.toast.sticky.update.detail'),
            icon: 'pi pi-cloud-upload',
            group: 'bc',
            actions: [
                { label: trans('sk-component.toast.sticky.update.confirm'), primary: true },
                { label: trans('sk-component.toast.sticky.update.cancel') },
            ],
        });
    };
</script>

<template>
    <SkCard>
        <template #title>{{ $t('sk-component.toast.title') }}</template>
        <template #subtitle>{{ $t('sk-component.toast.subtitle') }}</template>
        <template #actions>
            <a href="https://primevue.org/toast/" target="_blank" rel="noopener noreferrer">
                <Button :label="$t('sk-component.docs')" icon="pi pi-book" outlined size="small" />
            </a>
        </template>
        <template #content>
        <Message severity="info" :closable="false" class="mb-6">
            <span class="text-[13.5px] leading-relaxed">{{ trans('sk-component.toast.intro') }}</span>
        </Message>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <!-- 01 · Severity (accent default + progress bar) -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">01</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.toast.sections.severity.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.toast.sections.severity.desc') }}</p>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                    <div v-for="t in severities" :key="'sev-' + t.sev" class="sk-toast" :class="`sk-toast-${t.sev}`">
                        <span class="sk-toast-icon"><i :class="t.icon" /></span>
                        <div class="sk-toast-body">
                            <p class="sk-toast-summary">{{ $t(k(t.sev, 'summary')) }}</p>
                            <p class="sk-toast-detail">{{ $t(k(t.sev, 'detail')) }}</p>
                        </div>
                        <button type="button" class="sk-toast-close" :aria-label="$t('sk-button.close')"><i class="pi pi-times" /></button>
                        <span class="sk-toast-progress"><span :style="{ animation: 'none', transform: 'scaleX(0.62)' }" /></span>
                    </div>
                </div>
            </SkCard>

            <!-- 02 · Outlined & Solid -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">02</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.toast.sections.variants.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.toast.sections.variants.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3.5">
                    <div v-for="t in severities.slice(0, 2)" :key="'out-' + t.sev" class="sk-toast sk-toast-outlined" :class="`sk-toast-${t.sev}`">
                        <span class="sk-toast-icon"><i :class="t.icon" /></span>
                        <div class="sk-toast-body">
                            <p class="sk-toast-summary">{{ $t(k(t.sev, 'summary')) }}</p>
                            <p class="sk-toast-detail">{{ $t(k(t.sev, 'detail')) }}</p>
                        </div>
                        <button type="button" class="sk-toast-close" :aria-label="$t('sk-button.close')"><i class="pi pi-times" /></button>
                    </div>
                    <div v-for="t in severities.slice(2, 4)" :key="'sol-' + t.sev" class="sk-toast sk-toast-solid" :class="`sk-toast-${t.sev}`">
                        <span class="sk-toast-icon"><i :class="t.icon" /></span>
                        <div class="sk-toast-body">
                            <p class="sk-toast-summary">{{ $t(k(t.sev, 'summary')) }}</p>
                            <p class="sk-toast-detail">{{ $t(k(t.sev, 'detail')) }}</p>
                        </div>
                        <button type="button" class="sk-toast-close" :aria-label="$t('sk-button.close')"><i class="pi pi-times" /></button>
                    </div>
                </div>
            </SkCard>

            <!-- 03 · Sticky / actions -->
            <SkCard>
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">03</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.toast.sections.sticky.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.toast.sections.sticky.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-3.5">
                    <div class="sk-toast sk-toast-warn">
                        <span class="sk-toast-icon"><i class="pi pi-exclamation-triangle" /></span>
                        <div class="sk-toast-body">
                            <p class="sk-toast-summary">{{ $t('sk-component.toast.sticky.delete.summary') }}</p>
                            <p class="sk-toast-detail">{{ $t('sk-component.toast.sticky.delete.detail') }}</p>
                            <div class="sk-toast-actions">
                                <button type="button" class="sk-toast-btn sk-toast-btn-solid">{{ $t('sk-component.toast.sticky.delete.confirm') }}</button>
                                <button type="button" class="sk-toast-btn sk-toast-btn-ghost">{{ $t('sk-component.toast.sticky.delete.cancel') }}</button>
                            </div>
                        </div>
                        <button type="button" class="sk-toast-close" :aria-label="$t('sk-button.close')"><i class="pi pi-times" /></button>
                    </div>
                    <div class="sk-toast sk-toast-error">
                        <span class="sk-toast-icon"><i class="pi pi-times" /></span>
                        <div class="sk-toast-body">
                            <p class="sk-toast-summary">{{ $t('sk-component.toast.sticky.retry.summary') }}</p>
                        </div>
                        <button type="button" class="sk-toast-btn sk-toast-btn-ghost shrink-0">{{ $t('sk-component.toast.sticky.retry.retry') }}</button>
                    </div>
                </div>
            </SkCard>

            <!-- 04 · All Tailwind colors -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">04</span>
                    <div class="flex-1">
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.toast.sections.colors.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.toast.sections.colors.desc', { count: String(colors.length) }) }}</p>
                    </div>
                    <span class="inline-flex h-8 shrink-0 items-center gap-2 rounded-md border border-surface-200 px-2.5 font-mono text-[11px] text-surface-500 dark:border-surface-700 dark:text-surface-400">
                        <i class="pi pi-palette text-[12px]" />{{ $t('sk-component.toast.sections.colors.badge', { count: String(colors.length) }) }}
                    </span>
                </div>
                <div class="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
                    <div v-for="c in colors" :key="'tw-' + c" class="sk-toast" :class="`sk-toast-${c}`">
                        <span class="sk-toast-icon"><i class="pi pi-bell" /></span>
                        <div class="sk-toast-body">
                            <p class="sk-toast-summary">{{ cap(c) }}</p>
                            <p class="sk-toast-detail font-mono text-[11px]">severity="{{ c }}"</p>
                        </div>
                        <button type="button" class="sk-toast-close" :aria-label="$t('sk-button.close')"><i class="pi pi-times" /></button>
                    </div>
                </div>
            </SkCard>

            <!-- 05 · Live preview (fires real runtime toasts) -->
            <SkCard class="xl:col-span-2">
                <div class="mb-4 flex items-start gap-3">
                    <span class="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md font-mono text-[11.5px] font-semibold"
                          :style="{ color: 'var(--p-primary-color)', background: 'color-mix(in srgb, var(--p-primary-color) 10%, transparent)' }">05</span>
                    <div>
                        <h3 class="text-[14.5px] font-semibold tracking-tight text-surface-800 dark:text-surface-100">{{ $t('sk-component.toast.sections.live.title') }}</h3>
                        <p class="mt-0.5 text-[12.5px] leading-relaxed text-surface-500 dark:text-surface-400">{{ $t('sk-component.toast.sections.live.desc') }}</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <Button
                        v-for="t in severities"
                        :key="'live-' + t.sev"
                        :severity="t.sev === 'error' ? 'danger' : t.sev"
                        :label="cap(t.sev)"
                        :icon="t.icon"
                        size="small"
                        @click="fire(t.sev, t.icon)"
                    />
                    <Button severity="info" :label="$t('sk-component.toast.sections.live.update')" icon="pi pi-cloud-upload" outlined size="small" @click="fireUpdate" />
                    <Button severity="danger" :label="$t('sk-component.toast.sections.live.delete')" icon="pi pi-trash" outlined size="small" @click="fireDelete" />
                </div>
            </SkCard>
        </div>
        </template>
    </SkCard>
</template>
