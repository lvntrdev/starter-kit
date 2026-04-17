<script setup lang="ts">
    import ToggleSwitch from 'primevue/toggleswitch';
    import { computed } from 'vue';

    interface Props {
        modelValue?: boolean;
        label: string;
        description?: string;
        icon?: string;
    }

    const props = withDefaults(defineProps<Props>(), {
        modelValue: false,
        description: undefined,
        icon: undefined,
    });

    const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();

    const checked = computed(() => !!props.modelValue);

    function onToggle(value: boolean | undefined | null): void {
        emit('update:modelValue', !!value);
    }
</script>

<template>
    <label
        class="flex cursor-pointer items-center gap-3.5 rounded-xl border bg-surface-0 px-4 py-3.5 transition-colors dark:bg-surface-900"
        :class="
            checked
                ? 'border-primary-500 bg-primary-500/6 dark:bg-primary-500/15'
                : 'border-surface-200 hover:border-primary-400 dark:border-surface-700 dark:hover:border-primary-500'
        "
    >
        <div
            v-if="icon"
            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-500/10 dark:bg-primary-500/20"
        >
            <i :class="['pi', icon, 'text-lg text-primary-500']" />
        </div>
        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
            <div class="font-semibold leading-tight text-surface-800 dark:text-surface-100">{{ label }}</div>
            <div
                v-if="description"
                class="text-xs leading-snug text-surface-500 dark:text-surface-400"
            >
                {{ description }}
            </div>
        </div>
        <ToggleSwitch :model-value="checked" @update:model-value="onToggle" />
    </label>
</template>
