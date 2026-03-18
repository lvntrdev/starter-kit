<!-- resources/js/layouts/AdminLayout.vue -->
<script setup lang="ts">
    import { useSidebar } from '@/composables/useSidebar';
    import { useDarkMode } from '@/composables/useDarkMode';
    import { useFlash } from '@/composables/useFlash';
    import { usePageLoading } from '@/composables/usePageLoading';
    import { useToast } from 'primevue/usetoast';
    import { router } from '@inertiajs/vue3';
    import AdminSidebar from '@/layouts/components/AdminSidebar.vue';
    import AdminHeader from '@/layouts/components/AdminHeader.vue';
    import AdminFooter from '@/layouts/components/AdminFooter.vue';
    import AdminPageHeader from '@/layouts/components/AdminPageHeader.vue';
    import AppDialog from '@lvntr/components/AppDialog.vue';
    import { Head } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        title?: string;
        subtitle?: string;
        backUrl?: string | boolean;
    }

    withDefaults(defineProps<Props>(), {
        title: '',
        subtitle: '',
        backUrl: false,
    });

    const { isCollapsed, isMobileOpen, isMobile, toggle, closeMobile } = useSidebar();
    const { isNavigating } = usePageLoading();
    const { isDark, toggleDark } = useDarkMode();
    const { flash } = useFlash();
    const toast = useToast();

    const removeFinishListener = router.on('finish', () => {
        if (flash.value.success) {
            toast.add({
                severity: 'success',
                summary: trans('admin.layout.success'),
                detail: flash.value.success,
                life: 4000,
            });
        }
        if (flash.value.error) {
            toast.add({
                severity: 'error',
                summary: trans('admin.layout.error'),
                detail: flash.value.error,
                life: 6000,
            });
        }
        if (flash.value.warning) {
            toast.add({
                severity: 'warn',
                summary: trans('admin.layout.warning'),
                detail: flash.value.warning,
                life: 5000,
            });
        }
        if (flash.value.info) {
            toast.add({ severity: 'info', summary: trans('admin.layout.info'), detail: flash.value.info, life: 4000 });
        }
    });

    onUnmounted(() => {
        removeFinishListener();
    });
</script>

<template>
    <Head :title="title" />
    <div class="admin-layout">
        <!-- Sidebar -->
        <AdminSidebar
            :collapsed="isCollapsed"
            :mobile-open="isMobileOpen"
            :is-mobile="isMobile"
            @close-mobile="closeMobile"
        />

        <!-- Main Area -->
        <div
            class="admin-main"
            :class="{
                'admin-main--expanded': !isMobile && !isCollapsed,
                'admin-main--collapsed': !isMobile && isCollapsed,
                'admin-main--mobile': isMobile,
            }"
        >
            <!-- Header -->
            <AdminHeader
                :collapsed="isCollapsed"
                :is-mobile="isMobile"
                :is-dark="isDark"
                @toggle-sidebar="toggle"
                @toggle-dark="toggleDark"
            />

            <!-- Content -->
            <main
                class="admin-content"
                :class="{ 'opacity-60 pointer-events-none transition-opacity duration-150': isNavigating }"
            >
                <AdminPageHeader :title="title" :subtitle="subtitle" :back-url="backUrl">
                    <template #actions>
                        <slot name="page-actions" />
                    </template>
                </AdminPageHeader>

                <slot />
            </main>

            <!-- Footer -->
            <AdminFooter />
        </div>

        <!-- Global Overlays -->
        <ConfirmDialog />
        <Toast position="top-right" />
        <AppDialog />
    </div>
</template>
