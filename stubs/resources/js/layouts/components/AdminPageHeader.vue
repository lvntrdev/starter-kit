<script setup lang="ts">
    import { router } from '@inertiajs/vue3';

    interface Props {
        title?: string;
        subtitle?: string;
        backUrl?: string | boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        title: '',
        subtitle: '',
        backUrl: false,
    });

    const resolvedBackUrl = computed(() => {
        if (props.backUrl === true) {
            return window.history.length > 1 ? 'back' : null;
        }
        return props.backUrl || null;
    });

    function goBack() {
        if (props.backUrl === true) {
            window.history.back();
        } else if (typeof props.backUrl === 'string') {
            router.visit(props.backUrl);
        }
    }
</script>

<template>
    <div v-if="title || resolvedBackUrl || $slots.actions" class="admin-page-header">
        <div v-if="title" class="admin-page-header__title">
            <h1 class="admin-page-header__heading">
                {{ title }}
            </h1>
            <small v-if="subtitle" class="admin-page-header__subtitle">
                {{ subtitle }}
            </small>
        </div>
        <div v-if="resolvedBackUrl || $slots.actions" class="admin-page-header__actions">
            <Button
                v-if="resolvedBackUrl"
                icon="pi pi-arrow-left"
                :label="$t('sk-button.back')"
                severity="secondary"
                variant="outlined"
                @click="goBack"
            />
            <slot name="actions" />
        </div>
    </div>
</template>
