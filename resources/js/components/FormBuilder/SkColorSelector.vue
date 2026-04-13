<script setup lang="ts">
    const COLOR_PALETTE: Record<string, Record<number, string>> = {
        red: {
            50: '#fef2f2',
            100: '#fee2e2',
            200: '#fecaca',
            300: '#fca5a5',
            400: '#f87171',
            500: '#ef4444',
            600: '#dc2626',
            700: '#b91c1c',
            800: '#991b1b',
            900: '#7f1d1d',
            950: '#450a0a',
        },
        orange: {
            50: '#fff7ed',
            100: '#ffedd5',
            200: '#fed7aa',
            300: '#fdba74',
            400: '#fb923c',
            500: '#f97316',
            600: '#ea580c',
            700: '#c2410c',
            800: '#9a3412',
            900: '#7c2d12',
            950: '#431407',
        },
        amber: {
            50: '#fffbeb',
            100: '#fef3c7',
            200: '#fde68a',
            300: '#fcd34d',
            400: '#fbbf24',
            500: '#f59e0b',
            600: '#d97706',
            700: '#b45309',
            800: '#92400e',
            900: '#78350f',
            950: '#451a03',
        },
        yellow: {
            50: '#fefce8',
            100: '#fef9c3',
            200: '#fef08a',
            300: '#fde047',
            400: '#facc15',
            500: '#eab308',
            600: '#ca8a04',
            700: '#a16207',
            800: '#854d0e',
            900: '#713f12',
            950: '#422006',
        },
        lime: {
            50: '#f7fee7',
            100: '#ecfccb',
            200: '#d9f99d',
            300: '#bef264',
            400: '#a3e635',
            500: '#84cc16',
            600: '#65a30d',
            700: '#4d7c0f',
            800: '#3f6212',
            900: '#365314',
            950: '#1a2e05',
        },
        green: {
            50: '#f0fdf4',
            100: '#dcfce7',
            200: '#bbf7d0',
            300: '#86efac',
            400: '#4ade80',
            500: '#22c55e',
            600: '#16a34a',
            700: '#15803d',
            800: '#166534',
            900: '#14532d',
            950: '#052e16',
        },
        emerald: {
            50: '#ecfdf5',
            100: '#d1fae5',
            200: '#a7f3d0',
            300: '#6ee7b7',
            400: '#34d399',
            500: '#10b981',
            600: '#059669',
            700: '#047857',
            800: '#065f46',
            900: '#064e3b',
            950: '#022c22',
        },
        teal: {
            50: '#f0fdfa',
            100: '#ccfbf1',
            200: '#99f6e4',
            300: '#5eead4',
            400: '#2dd4bf',
            500: '#14b8a6',
            600: '#0d9488',
            700: '#0f766e',
            800: '#115e59',
            900: '#134e4a',
            950: '#042f2e',
        },
        cyan: {
            50: '#ecfeff',
            100: '#cffafe',
            200: '#a5f3fc',
            300: '#67e8f9',
            400: '#22d3ee',
            500: '#06b6d4',
            600: '#0891b2',
            700: '#0e7490',
            800: '#155e75',
            900: '#164e63',
            950: '#083344',
        },
        sky: {
            50: '#f0f9ff',
            100: '#e0f2fe',
            200: '#bae6fd',
            300: '#7dd3fc',
            400: '#38bdf8',
            500: '#0ea5e9',
            600: '#0284c7',
            700: '#0369a1',
            800: '#075985',
            900: '#0c4a6e',
            950: '#082f49',
        },
        blue: {
            50: '#eff6ff',
            100: '#dbeafe',
            200: '#bfdbfe',
            300: '#93c5fd',
            400: '#60a5fa',
            500: '#3b82f6',
            600: '#2563eb',
            700: '#1d4ed8',
            800: '#1e40af',
            900: '#1e3a8a',
            950: '#172554',
        },
        indigo: {
            50: '#eef2ff',
            100: '#e0e7ff',
            200: '#c7d2fe',
            300: '#a5b4fc',
            400: '#818cf8',
            500: '#6366f1',
            600: '#4f46e5',
            700: '#4338ca',
            800: '#3730a3',
            900: '#312e81',
            950: '#1e1b4b',
        },
        violet: {
            50: '#f5f3ff',
            100: '#ede9fe',
            200: '#ddd6fe',
            300: '#c4b5fd',
            400: '#a78bfa',
            500: '#8b5cf6',
            600: '#7c3aed',
            700: '#6d28d9',
            800: '#5b21b6',
            900: '#4c1d95',
            950: '#2e1065',
        },
        purple: {
            50: '#faf5ff',
            100: '#f3e8ff',
            200: '#e9d5ff',
            300: '#d8b4fe',
            400: '#c084fc',
            500: '#a855f7',
            600: '#9333ea',
            700: '#7e22ce',
            800: '#6b21a8',
            900: '#581c87',
            950: '#3b0764',
        },
        fuchsia: {
            50: '#fdf4ff',
            100: '#fae8ff',
            200: '#f5d0fe',
            300: '#f0abfc',
            400: '#e879f9',
            500: '#d946ef',
            600: '#c026d3',
            700: '#a21caf',
            800: '#86198f',
            900: '#701a75',
            950: '#4a044e',
        },
        pink: {
            50: '#fdf2f8',
            100: '#fce7f3',
            200: '#fbcfe8',
            300: '#f9a8d4',
            400: '#f472b6',
            500: '#ec4899',
            600: '#db2777',
            700: '#be185d',
            800: '#9d174d',
            900: '#831843',
            950: '#500724',
        },
        rose: {
            50: '#fff1f2',
            100: '#ffe4e6',
            200: '#fecdd3',
            300: '#fda4af',
            400: '#fb7185',
            500: '#f43f5e',
            600: '#e11d48',
            700: '#be123c',
            800: '#9f1239',
            900: '#881337',
            950: '#4c0519',
        },
    };

    type ColorFormat = 'hex' | 'name' | 'name-tone';

    interface Props {
        modelValue?: string;
        colors?: string[];
        tones?: number[];
        format?: ColorFormat;
        defaultTone?: number;
        disabled?: boolean;
        invalid?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        modelValue: '',
        colors: () => [
            'red',
            'orange',
            'amber',
            'yellow',
            'lime',
            'green',
            'emerald',
            'teal',
            'cyan',
            'sky',
            'blue',
            'indigo',
            'violet',
            'purple',
            'fuchsia',
            'pink',
            'rose',
        ],
        tones: () => [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950],
        format: 'name',
        defaultTone: 500,
        disabled: false,
        invalid: false,
    });

    const emit = defineEmits<{
        'update:modelValue': [value: string];
    }>();

    const selectedColor = ref<string>('');
    const selectedTone = ref<number>(props.defaultTone);

    function findByHex(hex: string): { color: string; tone: number } | null {
        const normalized = hex.toLowerCase();
        for (const [name, palette] of Object.entries(COLOR_PALETTE)) {
            for (const [tone, value] of Object.entries(palette)) {
                if (value.toLowerCase() === normalized) {
                    return { color: name, tone: Number(tone) };
                }
            }
        }
        return null;
    }

    function parseModelValue(value: string): void {
        if (!value) {
            selectedColor.value = '';
            selectedTone.value = props.defaultTone;
            return;
        }
        if (props.format === 'name') {
            selectedColor.value = value;
            selectedTone.value = props.defaultTone;
            return;
        }
        if (props.format === 'name-tone') {
            const idx = value.lastIndexOf('-');
            const tonePart = Number(value.slice(idx + 1));
            if (idx > 0 && !Number.isNaN(tonePart)) {
                selectedColor.value = value.slice(0, idx);
                selectedTone.value = tonePart;
            } else {
                selectedColor.value = value;
                selectedTone.value = props.defaultTone;
            }
            return;
        }
        const match = findByHex(value);
        if (match) {
            selectedColor.value = match.color;
            selectedTone.value = match.tone;
        } else {
            selectedColor.value = '';
            selectedTone.value = props.defaultTone;
        }
    }

    function computeEmitValue(): string {
        if (!selectedColor.value) {
            return '';
        }
        if (props.format === 'name') {
            return selectedColor.value;
        }
        if (props.format === 'name-tone') {
            return `${selectedColor.value}-${selectedTone.value}`;
        }
        return COLOR_PALETTE[selectedColor.value]?.[selectedTone.value] ?? '';
    }

    parseModelValue(props.modelValue);

    watch(
        () => props.modelValue,
        (value) => {
            if (value !== computeEmitValue()) {
                parseModelValue(value);
            }
        },
    );

    function updateColor(value: string): void {
        selectedColor.value = value;
        emit('update:modelValue', computeEmitValue());
    }

    function updateTone(tone: number): void {
        if (props.format === 'name') {
            return;
        }
        selectedTone.value = tone;
        emit('update:modelValue', computeEmitValue());
    }

    const showToneSelector = computed<boolean>(() => props.format !== 'name');

    const selectionLabel = computed<string>(() => {
        if (!selectedColor.value) {
            return '';
        }
        if (props.format === 'hex') {
            return COLOR_PALETTE[selectedColor.value]?.[selectedTone.value] ?? '';
        }
        return `${selectedColor.value}-${selectedTone.value}`;
    });

    function getToneStyle(color: string, tone: number): Record<string, string> {
        const hex = COLOR_PALETTE[color]?.[tone] ?? 'transparent';
        return { backgroundColor: hex, outlineColor: hex };
    }

    function isToneActive(color: string, tone: number): boolean {
        return selectedColor.value === color && selectedTone.value === tone;
    }
</script>

<template>
    <div class="flex flex-col gap-2">
        <Select
            :model-value="selectedColor"
            :options="colors"
            :disabled="disabled"
            :invalid="invalid"
            class="w-full"
            @update:model-value="updateColor"
        >
            <template #option="slotProps">
                <div class="flex items-center gap-3 w-full">
                    <span class="flex-1 capitalize">{{ slotProps.option }}</span>
                    <ul class="flex gap-1">
                        <li
                            v-for="tone in tones"
                            :key="`option-${String(slotProps.option)}-${tone}`"
                            class="size-4 rounded-full border-2 border-white outline-1 dark:border-surface-800"
                            :style="getToneStyle(String(slotProps.option), tone)"
                        />
                    </ul>
                </div>
            </template>
            <template #value="slotProps">
                <div v-if="slotProps.value" class="flex items-center gap-3 w-full">
                    <span class="flex-1 capitalize">{{ slotProps.value }}</span>
                    <ul class="flex gap-1">
                        <li
                            v-for="tone in tones"
                            :key="`value-${String(slotProps.value)}-${tone}`"
                            class="size-4 rounded-full border-2 border-white outline-1 dark:border-surface-800"
                            :style="getToneStyle(String(slotProps.value), tone)"
                        />
                    </ul>
                </div>
                <span v-else class="text-surface-400">Select a color</span>
            </template>
        </Select>

        <div
            v-if="showToneSelector && selectedColor"
            class="flex items-center gap-2 flex-wrap mt-2 border border-slate-300 rounded p-4 dark:border-surface-700"
        >
            <span class="font-bold text-surface-500 dark:text-surface-400 shrink-0">Tone:</span>
            <ul class="flex gap-1.5 flex-wrap">
                <li v-for="tone in tones" :key="`tone-${tone}`">
                    <button
                        type="button"
                        class="size-6 rounded-full border-2 outline-1 transition"
                        :class="
                            isToneActive(selectedColor, tone)
                                ? 'border-surface-900 dark:border-surface-0 scale-110'
                                : 'border-white dark:border-surface-800'
                        "
                        :style="getToneStyle(selectedColor, tone)"
                        :title="`${selectedColor}-${tone}`"
                        :disabled="disabled"
                        @click="updateTone(tone)"
                    />
                </li>
            </ul>
            <span class="text-surface-700 dark:text-surface-300 ml-1">{{ selectionLabel }}</span>
        </div>
    </div>
</template>
