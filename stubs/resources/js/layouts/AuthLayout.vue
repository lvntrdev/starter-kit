<!-- resources/js/Layouts/AuthLayout.vue -->
<script setup lang="ts">
    interface Props {
        title?: string;
        subtitle?: string;
    }

    import { computed } from 'vue';
    import { useDarkMode } from '@/composables/useDarkMode';
    import { Head, usePage } from '@inertiajs/vue3';

    withDefaults(defineProps<Props>(), {
        title: '',
        subtitle: '',
    });

    const { isDark, toggleDark } = useDarkMode();
    const appName = computed(() => (usePage().props.appName as string) || 'Laravel');
    const appLogo = computed(() => usePage().props.appLogo as string | null);
</script>

<template>
    <Head :title="title" />
    <div class="auth-layout">
        <button class="auth-dark-toggle" @click="toggleDark">
            <i :class="isDark ? 'pi pi-sun' : 'pi pi-moon'" />
        </button>
        <div class="auth-left">
            <template v-if="appLogo">
                <img :src="appLogo" alt="Logo" class="auth-left-logo">
            </template>
            <template v-else>
                <div class="auth-left-icon">
                    <i class="pi pi-shield text-5xl" />
                </div>
                <h1>{{ appName }}</h1>
            </template>
            <h2>Management Console Access</h2>
            <div class="dots">
                <div class="__icon" />
                <div class="__icon __active" />
                <div class="__icon" />
            </div>
        </div>
        <!-- END :: auth-left -->
        <div class="auth-right">
            <div class="auth-card">
                <div class="auth-header">
                    <slot name="header" />
                </div>
                <!-- END :: auth-header -->
                <slot />
            </div>
            <!-- END :: auth-card -->
            <div class="auth-footer">
                <slot name="footer" />
            </div>
            <!-- END :: auth-footer -->
        </div>
        <!-- END :: auth-right -->
    </div>
    <!-- END :: auth-layout -->
</template>
