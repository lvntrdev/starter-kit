<script setup lang="ts">
    import Checkbox from 'primevue/checkbox';
    import { computed } from 'vue';

    interface MimeOption {
        label: string;
        value: string;
        icon: string;
    }

    interface MimeCategory {
        titleKey: string;
        options: MimeOption[];
    }

    interface Props {
        modelValue?: string[] | null;
        categories?: MimeCategory[];
    }

    const props = withDefaults(defineProps<Props>(), {
        modelValue: () => [],
        categories: () => DEFAULT_CATEGORIES,
    });

    const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

    const selected = computed(() => props.modelValue ?? []);

    function isChecked(value: string): boolean {
        return selected.value.includes(value);
    }

    function toggle(value: string): void {
        const set = new Set(selected.value);
        if (set.has(value)) {
            set.delete(value);
        } else {
            set.add(value);
        }
        emit('update:modelValue', [...set]);
    }
</script>

<script lang="ts">
    const DEFAULT_CATEGORIES = [
        {
            titleKey: 'sk-setting.file_manager.mime_categories.images',
            options: [
                { label: 'JPEG', value: 'image/jpeg', icon: 'pi-image' },
                { label: 'PNG', value: 'image/png', icon: 'pi-image' },
                { label: 'GIF', value: 'image/gif', icon: 'pi-image' },
                { label: 'WebP', value: 'image/webp', icon: 'pi-image' },
                { label: 'SVG', value: 'image/svg+xml', icon: 'pi-image' },
            ],
        },
        {
            titleKey: 'sk-setting.file_manager.mime_categories.documents',
            options: [
                { label: 'PDF', value: 'application/pdf', icon: 'pi-file-pdf' },
                { label: 'Word (DOC)', value: 'application/msword', icon: 'pi-file-word' },
                {
                    label: 'Word (DOCX)',
                    value: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    icon: 'pi-file-word',
                },
                { label: 'Excel (XLS)', value: 'application/vnd.ms-excel', icon: 'pi-file-excel' },
                {
                    label: 'Excel (XLSX)',
                    value: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    icon: 'pi-file-excel',
                },
                { label: 'Plain Text', value: 'text/plain', icon: 'pi-file' },
                { label: 'CSV', value: 'text/csv', icon: 'pi-file' },
            ],
        },
        {
            titleKey: 'sk-setting.file_manager.mime_categories.archive',
            options: [{ label: 'ZIP', value: 'application/zip', icon: 'pi-folder' }],
        },
    ];
</script>

<template>
    <div class="flex flex-col gap-5">
        <section v-for="cat in categories" :key="cat.titleKey" class="flex flex-col gap-2.5">
            <h4
                class="border-b border-surface-200 pb-1.5 text-xs font-semibold uppercase tracking-[0.08em] text-surface-500 dark:border-surface-700 dark:text-surface-400"
            >
                {{ $t(cat.titleKey) }}
            </h4>
            <div class="grid grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-4">
                <label
                    v-for="opt in cat.options"
                    :key="opt.value"
                    class="flex cursor-pointer items-center gap-2.5 rounded-xl border bg-surface-0 px-3 py-2.5 transition-colors dark:bg-surface-900"
                    :class="
                        isChecked(opt.value)
                            ? 'border-primary-500 bg-primary-500/8 dark:bg-primary-500/15'
                            : 'border-surface-200 hover:border-primary-400 hover:bg-primary-500/5 dark:border-surface-700 dark:hover:border-primary-500 dark:hover:bg-primary-500/10'
                    "
                >
                    <Checkbox
                        :model-value="isChecked(opt.value)"
                        :binary="true"
                        @update:model-value="toggle(opt.value)"
                    />
                    <i
                        :class="[
                            'pi',
                            opt.icon,
                            'shrink-0 text-lg',
                            isChecked(opt.value)
                                ? 'text-primary-500'
                                : 'text-surface-500 dark:text-surface-400',
                        ]"
                    />
                    <span class="font-medium leading-tight text-surface-800 dark:text-surface-100">{{ opt.label }}</span>
                </label>
            </div>
        </section>
    </div>
</template>
