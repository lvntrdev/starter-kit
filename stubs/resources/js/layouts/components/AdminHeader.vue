<!-- resources/js/components/Admin/AdminHeader.vue -->
<script setup lang="ts">
    import { usePage, router } from '@inertiajs/vue3';
    import type { MenuItem } from 'primevue/menuitem';
    import type { User } from '@/types';
    import { trans } from 'laravel-vue-i18n';
    import locale from '@/routes/locale';

    interface Props {
        collapsed: boolean;
        isMobile: boolean;
        isDark: boolean;
    }

    defineProps<Props>();

    const emit = defineEmits<{
        toggleSidebar: [];
        toggleDark: [];
    }>();

    const page = usePage();
    const user = computed(() => page.props.auth?.user as User | undefined);
    const role = computed(() => (page.props.auth?.role as string) ?? '');
    const isLocal = computed(() => page.props.appEnv === 'local');
    const isDebug = computed(() => page.props.appDebug === true);

    const currentLocale = computed(() => (page.props.locale as string) ?? 'en');
    const availableLocales = computed(() => (page.props.availableLocales as Record<string, string>) ?? {});
    const showLocaleSwitcher = computed(() => Object.keys(availableLocales.value).length > 1);

    const localeMenuRef = ref();

    const localeMenuItems = computed<MenuItem[]>(() =>
        Object.entries(availableLocales.value).map(([code, label]) => ({
            label,
            code,
            active: code === currentLocale.value,
            command: () => switchLocale(code),
        })),
    );

    function toggleLocaleMenu(event: Event): void {
        localeMenuRef.value?.toggle(event);
    }

    function switchLocale(code: string): void {
        if (code === currentLocale.value) {
            return;
        }

        router.post(
            locale.update.url(),
            { locale: code },
            {
                preserveScroll: true,
                onSuccess: () => window.location.reload(),
            },
        );
    }

    const initials = computed(() => {
        if (!user.value) return '';
        const first = (user.value.first_name ?? '').charAt(0);
        const last = (user.value.last_name ?? '').charAt(0);
        return (first + last).toUpperCase();
    });

    const userMenuRef = ref();

    const userMenuItems = computed<MenuItem[]>(() => [
        {
            label: trans('sk-menu.profile'),
            icon: 'pi pi-user',
            command: () => router.visit('/profile'),
        },
        { separator: true },
        {
            label: trans('sk-menu.logout'),
            icon: 'pi pi-sign-out',
            danger: true,
            command: () => router.post('/logout'),
        },
    ]);

    function toggleUserMenu(event: Event): void {
        userMenuRef.value?.toggle(event);
    }
</script>

<template>
    <header class="admin-header">
        <div class="admin-header__left">
            <button
                class="admin-header__btn"
                :title="
                    isMobile
                        ? $t('sk-layout.open_menu')
                        : collapsed
                            ? $t('sk-layout.expand_menu')
                            : $t('sk-layout.collapse_menu')
                "
                @click="emit('toggleSidebar')"
            >
                <i :class="isMobile ? 'pi pi-bars' : collapsed ? 'pi pi-align-left' : 'pi pi-align-right'" />
            </button>

            <span v-if="isLocal" class="admin-header__tag admin-header__tag--dev"> Dev Mode </span>
            <span v-if="isDebug" class="admin-header__tag admin-header__tag--debug"> Debug Mode </span>
        </div>

        <div class="admin-header__right">
            <!-- Language Switcher (only when more than one language is active) -->
            <template v-if="showLocaleSwitcher">
                <button
                    class="admin-header__btn admin-header__btn--locale"
                    :title="availableLocales[currentLocale]"
                    @click="toggleLocaleMenu"
                >
                    <i class="pi pi-globe" />
                    <span class="admin-header__locale-code">{{ currentLocale.toUpperCase() }}</span>
                </button>
                <Menu ref="localeMenuRef" class="sk-locale-menu" :model="localeMenuItems" :popup="true">
                    <template #start>
                        <div class="sk-locale-menu__label">
                            {{ $t('sk-layout.language') }}
                        </div>
                    </template>
                    <template #item="{ item, props }">
                        <a
                            v-bind="props.action"
                            class="sk-locale-menu__item"
                            :class="{ 'sk-locale-menu__item--active': (item as any).active }"
                        >
                            <span class="sk-locale-menu__code">{{ (item as any).code?.toUpperCase() }}</span>
                            <span class="sk-locale-menu__name">{{ item.label }}</span>
                            <i v-if="(item as any).active" class="pi pi-check sk-locale-menu__check" />
                        </a>
                    </template>
                </Menu>
            </template>

            <button
                class="admin-header__btn"
                :title="isDark ? $t('sk-layout.light_mode') : $t('sk-layout.dark_mode')"
                @click="emit('toggleDark')"
            >
                <i :class="isDark ? 'pi pi-sun' : 'pi pi-moon'" />
            </button>

            <button class="admin-header__btn" :title="$t('sk-layout.notifications')">
                <i class="pi pi-bell" />
            </button>

            <!-- User Profile -->
            <button v-if="user" class="admin-header__user" @click="toggleUserMenu">
                <div class="admin-header__user-info">
                    <span class="admin-header__user-name">{{ user.full_name }}</span>
                    <span v-if="role" class="admin-header__user-role">
                        {{ role }}
                    </span>
                </div>
                <img v-if="user.avatar_url" :src="user.avatar_url" alt="Avatar" class="admin-header__avatar">
                <div v-else class="admin-header__avatar-placeholder">
                    {{ initials }}
                </div>
            </button>

            <Menu ref="userMenuRef" class="sk-user-menu" :model="userMenuItems" :popup="true">
                <template #start>
                    <div v-if="user" class="sk-user-menu__header">
                        <img v-if="user.avatar_url" :src="user.avatar_url" alt="Avatar" class="sk-user-menu__avatar">
                        <div v-else class="sk-user-menu__avatar sk-user-menu__avatar--placeholder">
                            {{ initials }}
                        </div>
                        <div class="sk-user-menu__identity">
                            <div class="sk-user-menu__name">
                                {{ user.full_name }}
                            </div>
                            <div class="sk-user-menu__email">
                                {{ user.email }}
                            </div>
                            <div v-if="role" class="sk-user-menu__role">
                                <i class="pi pi-shield" />
                                <span>{{ role }}</span>
                            </div>
                        </div>
                    </div>
                </template>
                <template #item="{ item, props }">
                    <a
                        v-bind="props.action"
                        class="sk-user-menu__item"
                        :class="{ 'sk-user-menu__item--danger': (item as any).danger }"
                    >
                        <span class="sk-user-menu__item-icon">
                            <i :class="item.icon" />
                        </span>
                        <span class="sk-user-menu__item-label">{{ item.label }}</span>
                        <i class="pi pi-arrow-up-right sk-user-menu__item-arrow" />
                    </a>
                </template>
            </Menu>
        </div>
    </header>
</template>
