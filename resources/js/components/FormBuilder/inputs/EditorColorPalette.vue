<script setup lang="ts">
    const palette: Record<string, Record<number, string>> = {
        red: { 300: '#fca5a5', 500: '#ef4444', 700: '#b91c1c', 900: '#7f1d1d' },
        orange: { 300: '#fdba74', 500: '#f97316', 700: '#c2410c', 900: '#7c2d12' },
        amber: { 300: '#fcd34d', 500: '#f59e0b', 700: '#b45309', 900: '#78350f' },
        yellow: { 300: '#fde047', 500: '#eab308', 700: '#a16207', 900: '#713f12' },
        lime: { 300: '#bef264', 500: '#84cc16', 700: '#4d7c0f', 900: '#365314' },
        green: { 300: '#86efac', 500: '#22c55e', 700: '#15803d', 900: '#14532d' },
        emerald: { 300: '#6ee7b7', 500: '#10b981', 700: '#047857', 900: '#064e3b' },
        teal: { 300: '#5eead4', 500: '#14b8a6', 700: '#0f766e', 900: '#134e4a' },
        cyan: { 300: '#67e8f9', 500: '#06b6d4', 700: '#0e7490', 900: '#164e63' },
        sky: { 300: '#7dd3fc', 500: '#0ea5e9', 700: '#0369a1', 900: '#0c4a6e' },
        blue: { 300: '#93c5fd', 500: '#3b82f6', 700: '#1d4ed8', 900: '#1e3a8a' },
        indigo: { 300: '#a5b4fc', 500: '#6366f1', 700: '#4338ca', 900: '#312e81' },
        violet: { 300: '#c4b5fd', 500: '#8b5cf6', 700: '#6d28d9', 900: '#4c1d95' },
        purple: { 300: '#d8b4fe', 500: '#a855f7', 700: '#7e22ce', 900: '#581c87' },
        fuchsia: { 300: '#f0abfc', 500: '#d946ef', 700: '#a21caf', 900: '#701a75' },
        pink: { 300: '#f9a8d4', 500: '#ec4899', 700: '#be185d', 900: '#831843' },
        rose: { 300: '#fda4af', 500: '#f43f5e', 700: '#be123c', 900: '#881337' },
    };

    const neutrals: Record<string, string> = {
        white: '#ffffff',
        'surface-400': '#9ca3af',
        'surface-700': '#374151',
        black: '#000000',
    };

    const tones = [300, 500, 700, 900] as const;
    const colorNames = Object.keys(palette);

    interface Props {
        current?: string | null;
    }
    defineProps<Props>();

    const emit = defineEmits<{
        pick: [hex: string];
        clear: [];
    }>();

    function isActive(hex: string, current?: string | null): boolean {
        if (!current) return false;
        return current.toLowerCase() === hex.toLowerCase();
    }
</script>

<template>
    <div class="sk-rte__color-panel">
        <div class="space-y-1">
            <div v-for="tone in tones" :key="tone" class="flex gap-1">
                <button
                    v-for="color in colorNames"
                    :key="`${color}-${tone}`"
                    type="button"
                    class="size-5 rounded transition hover:scale-110 focus:outline-none focus:ring-2 focus:ring-primary-400"
                    :class="{ 'ring-2 ring-primary-500': isActive(palette[color][tone], current) }"
                    :style="{ backgroundColor: palette[color][tone] }"
                    :title="`${color}-${tone}`"
                    @click="emit('pick', palette[color][tone])"
                />
            </div>
        </div>

        <div class="mt-2 flex items-center gap-1 border-t pt-2 border-surface-200 dark:border-surface-700">
            <button
                v-for="(hex, name) in neutrals"
                :key="name"
                type="button"
                class="size-5 rounded border border-surface-300 transition hover:scale-110 focus:outline-none focus:ring-2 focus:ring-primary-400 dark:border-surface-600"
                :class="{ 'ring-2 ring-primary-500': isActive(hex, current) }"
                :style="{ backgroundColor: hex }"
                :title="name"
                @click="emit('pick', hex)"
            />
            <button
                type="button"
                class="ml-auto text-xs text-surface-600 underline hover:text-surface-900 dark:text-surface-300 dark:hover:text-surface-0"
                @click="emit('clear')"
            >
                {{ $t('sk-editor.color_clear') }}
            </button>
        </div>
    </div>
</template>
