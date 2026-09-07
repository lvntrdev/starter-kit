<!-- resources/js/components/Admin/AdminHeader.vue -->
<script setup lang="ts">
    import { usePage, router } from '@inertiajs/vue3';
    import type { MenuItem } from 'primevue/menuitem';
    import type { User } from '@/types';
    import { ACCENT_COLORS, ACCENT_SWATCH } from '@/composables/useAccentColor';
    import type { AccentColor, SidebarStyle } from '@/composables/useAccentColor';
    import { trans } from 'laravel-vue-i18n';
    import locale from '@/routes/locale';
    import apiRoutes from '@/routes/api-routes';
    import { useCan } from '@/composables/useCan';

    const { can, hasRole } = useCan();

    interface Props {
        collapsed: boolean;
        isMobile: boolean;
        isDark: boolean;
        accent: AccentColor;
        sidebarStyle: SidebarStyle;
        /**
         * Back-compat bridge, not part of the current contract. The aura page
         * header now lives inside the content card, so AdminLayout no longer
         * passes these. They stay because `sk:update` refreshes this file and
         * AdminLayout.vue independently: an app that customised the layout keeps
         * the old one, which still feeds the page title into the topbar, and
         * dropping the props here would blank the heading on those screens.
         * Safe to delete once your own AdminLayout.vue no longer binds them.
         */
        pageTitle?: string;
        pageSubtitle?: string;
        showBack?: boolean;
    }

    withDefaults(defineProps<Props>(), {
        pageTitle: '',
        pageSubtitle: '',
        showBack: false,
    });

    const emit = defineEmits<{
        toggleSidebar: [];
        toggleDark: [];
        setAccent: [color: AccentColor];
        setSidebarStyle: [style: SidebarStyle];
        /** Back-compat, paired with `showBack` — see the props above. */
        back: [];
    }>();

    const page = usePage();
    const user = computed(() => page.props.auth?.user as User | undefined);
    const role = computed(() => page.props.auth?.role ?? '');
    const isLocal = computed(() => page.props.appEnv === 'local');
    const isDebug = computed(() => page.props.appDebug === true);

    const currentLocale = computed(() => (page.props.locale as string) ?? 'en');
    const availableLocales = computed(() => (page.props.availableLocales as Record<string, string>) ?? {});
    const showLocaleSwitcher = computed(() => Object.keys(availableLocales.value).length > 1);
    const currentLocaleLabel = computed(
        () => availableLocales.value[currentLocale.value] ?? currentLocale.value.toUpperCase(),
    );

    const localeItems = computed<MenuItem[]>(() =>
        Object.entries(availableLocales.value).map(([code, label]) => ({
            label,
            localeCode: code,
            localeActive: code === currentLocale.value,
            command: () => switchLocale(code),
        })),
    );

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
            label: trans('sk-menu.my_profile'),
            icon: 'pi pi-user',
            command: () => router.visit('/profile'),
        },
        ...(showLocaleSwitcher.value
            ? [
                  { separator: true },
                  {
                      label: trans('sk-menu.language'),
                      icon: 'pi pi-globe',
                      items: localeItems.value,
                  } as MenuItem,
              ]
            : []),
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
        // PrimeVue gates hover-to-open submenus behind an internal `dirty`
        // flag that only flips true after a click. Pre-arm it so the language
        // submenu opens on first hover, no click needed.
        nextTick(() => {
            if (userMenuRef.value) {
                (userMenuRef.value as unknown as { dirty: boolean }).dirty = true;
            }
        });
    }

    // Appearance popover — dark mode + accent color picker.
    const appearanceRef = ref();
    const appearanceOpen = ref(false);

    function toggleAppearance(event: Event): void {
        appearanceRef.value?.toggle(event);
    }

    function selectAccent(color: AccentColor): void {
        emit('setAccent', color);
    }

    function selectSidebarStyle(style: SidebarStyle): void {
        emit('setSidebarStyle', style);
    }

    // ── Developer popover ──
    const developerRef = ref();
    const developerOpen = ref(false);

    function toggleDeveloper(event: Event): void {
        developerRef.value?.toggle(event);
    }

    function openApiRoutes(): void {
        developerRef.value?.hide();
        router.visit(apiRoutes.index.url());
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

      <span
        v-if="isLocal"
        class="admin-header__tag admin-header__tag--dev"
      > Dev Mode </span>
      <span
        v-if="isDebug"
        class="admin-header__tag admin-header__tag--debug"
      > Debug Mode </span>

      <!-- Back-compat: a customised AdminLayout.vue still routes the aura page
           title and its back affordance through the topbar. Nothing binds these
           props by default. -->
      <span
        v-if="pageTitle || showBack"
        class="admin-header__divider"
      />
      <button
        v-if="showBack"
        type="button"
        class="admin-header__btn admin-header__back"
        :title="$t('sk-button.back')"
        :aria-label="$t('sk-button.back')"
        @click="emit('back')"
      >
        <i class="pi pi-arrow-left" />
      </button>
      <div
        v-if="pageTitle"
        class="admin-header__page"
      >
        <span class="admin-header__page-title">{{ pageTitle }}</span>
        <span
          v-if="pageSubtitle"
          class="admin-header__page-subtitle"
        >{{ pageSubtitle }}</span>
      </div>
    </div>

    <div class="admin-header__right">
      <!-- Appearance popover (trigger lives in the action cluster below) -->
      <Popover
        ref="appearanceRef"
        @show="appearanceOpen = true"
        @hide="appearanceOpen = false"
      >
        <div class="w-[360px] max-w-[90vw]">
          <!-- Dark mode -->
          <div class="flex items-center justify-between gap-4">
            <div class="flex flex-col">
              <span class="text-sm font-semibold text-surface-800 dark:text-surface-0">
                {{ $t('sk-layout.dark_mode') }}
              </span>
              <span class="text-xs text-surface-500">
                {{ $t('sk-layout.dark_mode_hint') }}
              </span>
            </div>
            <ToggleSwitch
              :model-value="isDark"
              @update:model-value="emit('toggleDark')"
            />
          </div>

          <hr class="my-4 border-surface-200 dark:border-surface-700">

          <!-- Sidebar style: colored vs light (light mode only) -->
          <div class="mb-3 flex flex-col">
            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">
              {{ $t('sk-layout.sidebar_style') }}
            </span>
          </div>

          <div class="grid grid-cols-2 gap-3">
            <button
              type="button"
              class="flex items-center gap-2.5 rounded-[5px] border px-3.5 py-3 text-left transition"
              :class="
                sidebarStyle === 'colored'
                  ? 'border-primary-500 bg-primary-50 dark:border-primary-500 dark:bg-primary-500/10'
                  : 'border-surface-200 hover:border-surface-300 dark:border-surface-700 dark:hover:border-surface-600'
              "
              @click="selectSidebarStyle('colored')"
            >
              <span
                class="h-5 w-5 shrink-0 rounded-md shadow-sm ring-1 ring-inset ring-black/10 dark:ring-white/15"
                style="background: var(--p-primary-color)"
              />
              <span
                class="text-sm font-medium"
                :class="sidebarStyle === 'colored' ? 'text-surface-800 dark:text-surface-0' : 'text-surface-500'"
              >
                {{ $t('sk-layout.sidebar_style_colored') }}
              </span>
            </button>

            <button
              type="button"
              class="flex items-center gap-2.5 rounded-[5px] border px-3.5 py-3 text-left transition"
              :class="
                sidebarStyle === 'light'
                  ? 'border-primary-500 bg-primary-50 dark:border-primary-500 dark:bg-primary-500/10'
                  : 'border-surface-200 hover:border-surface-300 dark:border-surface-700 dark:hover:border-surface-600'
              "
              @click="selectSidebarStyle('light')"
            >
              <span class="h-5 w-5 shrink-0 rounded-md border border-surface-300 bg-surface-0 shadow-sm dark:border-surface-500" />
              <span
                class="text-sm font-medium"
                :class="sidebarStyle === 'light' ? 'text-surface-800 dark:text-surface-0' : 'text-surface-500'"
              >
                {{ $t('sk-layout.sidebar_style_light') }}
              </span>
            </button>
          </div>

          <!-- Sidebar color theme -->
          <div class="mb-3 mt-5 flex flex-col">
            <span class="text-xs font-semibold uppercase tracking-wide text-surface-500">
              {{ $t('sk-layout.accent_color') }}
            </span>
          </div>

          <div class="grid grid-cols-6 gap-x-2 gap-y-3">
            <!-- Default -->
            <button
              type="button"
              class="flex flex-col items-center gap-1"
              :title="$t('sk-layout.color_default')"
              @click="selectAccent('default')"
            >
              <span
                class="flex h-12 w-full items-center justify-center rounded-[5px] border border-surface-200 bg-surface-0 text-surface-400 transition dark:border-surface-700 dark:bg-surface-800"
                :class="{ 'ring-2 ring-primary-500 ring-offset-1 ring-offset-surface-0 dark:ring-offset-surface-900': accent === 'default' }"
              >
                <i class="pi pi-ban" />
              </span>
              <span class="w-full truncate text-center text-[0.7rem] text-surface-500">
                {{ $t('sk-layout.color_default') }}
              </span>
            </button>

            <!-- Colors -->
            <button
              v-for="color in ACCENT_COLORS"
              :key="color"
              type="button"
              class="flex flex-col items-center gap-1"
              :title="color"
              @click="selectAccent(color)"
            >
              <span
                class="flex h-12 w-full items-center justify-center rounded-[5px] text-white shadow-sm transition"
                :style="{ background: ACCENT_SWATCH[color] }"
                :class="{ 'ring-2 ring-surface-900 ring-offset-1 ring-offset-surface-0 dark:ring-surface-0 dark:ring-offset-surface-900': accent === color }"
              >
                <i
                  v-if="accent === color"
                  class="pi pi-check text-sm"
                />
              </span>
              <span class="w-full truncate text-center text-[0.7rem] capitalize text-surface-500">
                {{ color }}
              </span>
            </button>
          </div>
        </div>
      </Popover>

      <!-- Developer (system admins only; inner items gated per permission) -->
      <button
        v-if="hasRole('system_admin')"
        class="admin-header__btn"
        :class="{ 'admin-header__btn--active': developerOpen }"
        :title="$t('sk-layout.developer')"
        @click="toggleDeveloper"
      >
        <i class="pi pi-code" />
      </button>
      <Popover
        ref="developerRef"
        class="sk-flush-popover"
        @show="developerOpen = true"
        @hide="developerOpen = false"
      >
        <div class="w-[340px] max-w-[92vw]">
          <div class="flex items-center gap-2 px-4 py-3">
            <i class="pi pi-code text-xs text-surface-400" />
            <span class="text-sm font-semibold text-surface-800 dark:text-surface-0">
              {{ $t('sk-layout.developer') }}
            </span>
          </div>
          <hr class="border-surface-200 dark:border-surface-700">
          <div>
            <button
              v-if="can('api-routes.read')"
              type="button"
              class="flex w-full items-center gap-3 border-b border-surface-100 px-4 py-3 text-left transition-colors hover:bg-surface-50 dark:border-surface-800 dark:hover:bg-surface-800"
              @click="openApiRoutes"
            >
              <span class="flex size-10 shrink-0 items-center justify-center rounded-[5px] bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-300">
                <i class="pi pi-sitemap" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-surface-800 dark:text-surface-100">{{ $t('sk-menu.api_routes') }}</span>
                <span class="block truncate text-xs text-surface-500">{{ $t('sk-layout.api_routes_hint') }}</span>
              </span>
              <i class="pi pi-chevron-right text-xs text-surface-400" />
            </button>
            <a
              href="https://laravel.com/docs"
              target="_blank"
              rel="noopener"
              class="flex w-full items-center gap-3 border-b border-surface-100 px-4 py-3 text-left no-underline transition-colors hover:bg-surface-50 dark:border-surface-800 dark:hover:bg-surface-800"
            >
              <span class="flex size-10 shrink-0 items-center justify-center rounded-[5px] bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-300">
                <i class="pi pi-book" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-surface-800 dark:text-surface-100">{{ $t('sk-menu.laravel_docs') }}</span>
                <span class="block truncate text-xs text-surface-500">laravel.com/docs</span>
              </span>
              <i class="pi pi-external-link text-xs text-surface-400" />
            </a>
            <a
              href="https://starter-kit.lvntr.dev"
              target="_blank"
              rel="noopener"
              class="flex w-full items-center gap-3 px-4 py-3 text-left no-underline transition-colors hover:bg-surface-50 dark:hover:bg-surface-800"
            >
              <span class="flex size-10 shrink-0 items-center justify-center rounded-[5px] bg-primary-50 text-primary-600 dark:bg-primary-500/15 dark:text-primary-300">
                <i class="pi pi-box" />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-surface-800 dark:text-surface-100">{{ $t('sk-menu.kits_docs') }}</span>
                <span class="block truncate text-xs text-surface-500">starter-kit.lvntr.dev</span>
              </span>
              <i class="pi pi-external-link text-xs text-surface-400" />
            </a>
          </div>
        </div>
      </Popover>

      <!-- Help -->
      <a
        href="https://starter-kit.lvntr.dev"
        target="_blank"
        rel="noopener"
        class="admin-header__btn"
        :title="$t('sk-layout.help')"
      >
        <i class="pi pi-question-circle" />
      </a>

      <!-- Appearance (palette) — immediately left of the user badge -->
      <button
        class="admin-header__btn"
        :class="{ 'admin-header__btn--active': appearanceOpen }"
        :title="$t('sk-layout.appearance')"
        @click="toggleAppearance"
      >
        <i class="pi pi-palette" />
      </button>

      <span class="admin-header__divider" />

      <!-- User Profile -->
      <button
        v-if="user"
        class="admin-header__user"
        @click="toggleUserMenu"
      >
        <img
          v-if="user.avatar_url"
          :src="user.avatar_url"
          alt="Avatar"
          class="admin-header__avatar"
        >
        <div
          v-else
          class="admin-header__avatar-placeholder"
        >
          {{ initials }}
        </div>
        <div class="admin-header__user-info">
          <span class="admin-header__user-name">{{ user.full_name }}</span>
          <span
            v-if="role"
            class="admin-header__user-role"
          >
            {{ role }}
          </span>
        </div>
        <i class="pi pi-chevron-down admin-header__user-chevron" />
      </button>

      <TieredMenu
        ref="userMenuRef"
        class="sk-user-menu"
        :model="userMenuItems"
        :popup="true"
      >
        <template #start>
          <div
            v-if="user"
            class="sk-user-menu__header"
          >
            <img
              v-if="user.avatar_url"
              :src="user.avatar_url"
              alt="Avatar"
              class="sk-user-menu__avatar"
            >
            <div
              v-else
              class="sk-user-menu__avatar sk-user-menu__avatar--placeholder"
            >
              {{ initials }}
            </div>
            <div class="sk-user-menu__identity">
              <div class="sk-user-menu__name">
                {{ user.full_name }}
              </div>
              <div class="sk-user-menu__email">
                {{ user.email }}
              </div>
              <div
                v-if="role"
                class="sk-user-menu__role"
              >
                <i class="pi pi-shield" />
                <span>{{ role }}</span>
              </div>
            </div>
          </div>
        </template>
        <template #item="{ item, props, hasSubmenu }">
          <a
            v-bind="props.action"
            class="sk-user-menu__item"
            :class="{
              'sk-user-menu__item--danger': (item as any).danger,
              'sk-user-menu__item--active': (item as any).localeActive,
            }"
          >
            <span
              v-if="(item as any).localeCode"
              class="sk-user-menu__item-code"
            >
              {{ (item as any).localeCode.toUpperCase() }}
            </span>
            <span
              v-else
              class="sk-user-menu__item-icon"
            >
              <i :class="item.icon" />
            </span>
            <span class="sk-user-menu__item-label">
              {{ item.label }}<template v-if="hasSubmenu">: {{ currentLocaleLabel }}</template>
            </span>
            <i
              v-if="(item as any).localeActive"
              class="pi pi-check sk-user-menu__item-arrow sk-user-menu__item-arrow--static"
            />
            <i
              v-else-if="hasSubmenu"
              class="pi pi-chevron-left sk-user-menu__item-arrow sk-user-menu__item-arrow--static"
            />
          </a>
        </template>
      </TieredMenu>
    </div>
  </header>
</template>
