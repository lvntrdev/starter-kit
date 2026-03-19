<!-- resources/js/components/Admin/AdminSidebar.vue -->
<script setup lang="ts">
    import { usePage } from '@inertiajs/vue3';
    import { useAdminMenu } from '@/composables/useAdminMenu';
    import SidebarMenuItem from '@/layouts/components/SidebarMenuItem.vue';
    import AdminSidebarFooter from '@/layouts/components/AdminSidebarFooter.vue';

    interface Props {
        collapsed: boolean;
        mobileOpen: boolean;
        isMobile: boolean;
    }

    const props = defineProps<Props>();

    const emit = defineEmits<{
        closeMobile: [];
    }>();

    const { items: menuItems, isItemActive, isGroupOpen } = useAdminMenu();
    provide('adminMenu', { isItemActive, isGroupOpen });

    const appName = computed(() => usePage().props.appName as string);
    const appLogo = computed(() => usePage().props.appLogo as string | null);

    // Whether the sidebar is visually collapsed via CSS (icon-only mode)
    const isCollapsed = computed(() => props.collapsed && !props.isMobile);

    // On hover the sidebar expands via CSS while Vue state stays collapsed.
    // effectiveCollapsed resolves that mismatch for child components.
    const isHovered = ref(false);
    const effectiveCollapsed = computed(() => isCollapsed.value && !isHovered.value);
</script>

<template>
    <!-- Mobile Overlay -->
    <Transition name="fade">
        <div v-if="isMobile && mobileOpen" class="admin-overlay" @click="emit('closeMobile')" />
    </Transition>

    <!-- Sidebar -->
    <aside
        class="admin-sidebar"
        :class="{
            'admin-sidebar--collapsed': isCollapsed,
            'admin-sidebar--hidden': isMobile && !mobileOpen,
        }"
        @mouseenter="isHovered = true"
        @mouseleave="isHovered = false"
    >
        <!-- Logo -->
        <div class="admin-sidebar__logo">
            <template v-if="appLogo">
                <img :src="appLogo" alt="Logo" class="admin-sidebar__logo-img">
            </template>
            <template v-else>
                <div class="admin-sidebar__logo-icon">
                    <i class="pi pi-box" />
                </div>
                <span class="admin-sidebar__logo-text" :class="effectiveCollapsed ? 'opacity-0' : 'opacity-100'">
                    {{ appName }}
                </span>
            </template>
        </div>

        <!-- Navigation -->
        <nav class="admin-sidebar__nav">
            <SidebarMenuItem
                v-for="(item, index) in menuItems"
                :key="index"
                :item="item"
                :collapsed="effectiveCollapsed"
                @nav-click="emit('closeMobile')"
            />
        </nav>

        <!-- Footer -->
        <AdminSidebarFooter :collapsed="effectiveCollapsed" />
    </aside>
</template>
