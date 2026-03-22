<!-- resources/js/components/Admin/SidebarMenuItem.vue -->
<script setup lang="ts">
    import { Link } from '@inertiajs/vue3';
    import type { MenuItem, MenuContext } from '@/types';

    defineOptions({ name: 'SidebarMenuItem' });

    interface Props {
        item: MenuItem;
        collapsed: boolean;
        depth?: number;
    }

    const props = withDefaults(defineProps<Props>(), {
        depth: 0,
    });

    const emit = defineEmits<{
        navClick: [];
    }>();

    const { isItemActive, isGroupOpen } = inject<MenuContext>('adminMenu')!;

    const isActive = computed(() => isItemActive(props.item));
    const isOpen = ref(isGroupOpen(props.item));

    watch(
        () => props.collapsed,
        (collapsed) => {
            if (collapsed) {
                isOpen.value = false;
            } else {
                isOpen.value = isGroupOpen(props.item);
            }
        },
    );

    function toggle(): void {
        if (!props.collapsed) {
            isOpen.value = !isOpen.value;
        }
    }

    function handleNavClick(): void {
        emit('navClick');
    }
</script>

<template>
    <!-- SECTION TITLE -->
    <div v-if="item.section" class="nav-section">
        <span v-if="!collapsed" class="nav-section__title">{{ $t(item.title) }}</span>
        <span v-else class="nav-section__divider" />
    </div>

    <!-- GROUP -->
    <div v-else-if="item.children?.length" class="nav-group-wrapper">
        <button
            class="nav-group"
            :class="{ 'nav-group--active': isOpen || isGroupOpen(item), 'nav-group--url-active': isGroupOpen(item) }"
            @click="toggle"
        >
            <div class="nav-icon">
                <i v-if="item.icon" :class="item.icon" />
            </div>
            <span class="nav-label" :class="{ 'nav-label--hidden': collapsed }">
                {{ $t(item.title) }}
            </span>
            <i v-if="!collapsed" class="pi pi-chevron-right nav-chevron" :class="{ 'nav-chevron--open': isOpen }" />
        </button>

        <Transition name="submenu">
            <div v-if="isOpen && !collapsed" class="nav-submenu">
                <SidebarMenuItem
                    v-for="child in item.children"
                    :key="child.title"
                    :item="child"
                    :collapsed="false"
                    :depth="depth + 1"
                    @nav-click="emit('navClick')"
                />
            </div>
        </Transition>
    </div>

    <!-- EXTERNAL LINK -->
    <a
        v-else-if="item.external && item.href"
        :href="item.href"
        target="_blank"
        rel="noopener noreferrer"
        class="nav-link"
        :class="{ 'nav-link--child': depth > 0 }"
        @click="handleNavClick"
    >
        <div v-if="depth === 0" class="nav-icon">
            <i v-if="item.icon" :class="item.icon" />
        </div>
        <div v-else-if="depth > 0" class="nav-dot" />
        <span class="nav-label" :class="{ 'nav-label--hidden': collapsed }">
            {{ $t(item.title) }}
        </span>
        <i v-if="!collapsed" class="pi pi-external-link nav-external" />
    </a>

    <!-- INTERNAL LINK -->
    <Link
        v-else-if="item.href"
        :href="item.href"
        class="nav-link"
        :class="{
            'nav-link--active': isActive && depth === 0,
            'nav-link--child': depth > 0,
            'nav-link--child-active': isActive && depth > 0,
        }"
        @click="handleNavClick"
    >
        <div v-if="depth === 0" class="nav-icon">
            <i v-if="item.icon" :class="item.icon" />
        </div>
        <div v-else-if="depth > 0" class="nav-dot" :class="{ 'nav-dot--active': isActive }" />
        <span class="nav-label" :class="{ 'nav-label--hidden': collapsed }">
            {{ $t(item.title) }}
        </span>
    </Link>
</template>
