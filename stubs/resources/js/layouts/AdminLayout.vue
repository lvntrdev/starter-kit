<!-- resources/js/layouts/AdminLayout.vue -->
<script setup lang="ts">
    import { useAccentColor } from '@/composables/useAccentColor';
    import { useAppearanceDefaults } from '@/composables/useAppearanceDefaults';
    import { useDarkMode } from '@/composables/useDarkMode';
    import { useTheme } from '@/composables/useTheme';
    import { useFlash } from '@/composables/useFlash';
    import { SK_PAGE_HEADER_KEY } from '@lvntr/components/ui/pageHeader';
    import AppShell from '@/layouts/AppShell.vue';
    import AdminFooter from '@/layouts/components/AdminFooter.vue';
    import AdminHeader from '@/layouts/components/AdminHeader.vue';
    import AdminPageHeader from '@/layouts/components/AdminPageHeader.vue';
    import AdminSidebar from '@/layouts/components/AdminSidebar.vue';
    import { Head, router } from '@inertiajs/vue3';
    import AppDialog from '@lvntr/components/ui/AppDialog.vue';
    import ConfirmDialogComponent from '@lvntr/components/ui/ConfirmDialogComponent.vue';
    import ImageLightbox from '@lvntr/components/ui/ImageLightbox.vue';
    import { trans } from 'laravel-vue-i18n';
    import { useToast } from 'primevue/usetoast';

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

    const { isDark, toggleDark } = useDarkMode();
    const { accent, setAccent, sidebarStyle, setSidebarStyle } = useAccentColor();
    const { applyFavicon } = useAppearanceDefaults();
    const { theme } = useTheme();
    const slots = useSlots();

    // Page header placement is a THEME decision, never a page decision:
    //   main  → the header renders in place, as a strip above the content.
    //   aura  → the first content card (the datatable card, the form card, an active
    //           tab's card) draws it in its own head, so the page never shows a
    //           second title block. `SkCard` claims it; see `ui/pageHeader.ts`.
    // Pages stay theme-agnostic — they always pass `title`/`subtitle`/`back-url` and
    // fill `#page-actions`, whichever theme is active.
    const hostPageHeaderInCard = computed(() => theme.value === 'aura');
    const pageHeaderCandidates = ref<symbol[]>([]);

    // ONE definition of the header, rendered either here or by the hosting card.
    // A hosting card that already carries its own title asks for `hideTitle`, so the
    // header contributes only the back button and the page actions to that card head.
    function renderPageHeader(options: { hideTitle?: boolean } = {}) {
        const bare = options.hideTitle === true;

        return h(
            AdminPageHeader,
            {
                title: bare ? '' : props.title,
                subtitle: bare ? '' : props.subtitle,
                backUrl: props.backUrl,
            },
            // Passed only when the page fills it: an always-present slot function
            // makes AdminPageHeader render its (empty) wrapper, which then eats a
            // flex gap in whatever head or toolbar hosts the header.
            slots['page-actions'] ? { actions: () => slots['page-actions']!() } : undefined,
        );
    }

    provide(SK_PAGE_HEADER_KEY, {
        candidates: pageHeaderCandidates,
        enabled: hostPageHeaderInCard,
        render: renderPageHeader,
    });

    // In a hosting theme the layout draws nothing until it knows no card wants the
    // header: cards register during the content's own first render, so rendering
    // eagerly would double the header for a frame. A page with no card at all gets
    // it back in place right after mount.
    const pageHeaderMounted = ref(false);
    onMounted(() => {
        pageHeaderMounted.value = true;
    });
    const renderPageHeaderInPlace = computed(() => {
        if (!hostPageHeaderInCard.value) return true;
        return pageHeaderMounted.value && pageHeaderCandidates.value.length === 0;
    });

    const { flash } = useFlash();
    const toast = useToast();

    // Apply the admin-set favicon on boot (SSR-safe; no-op when none configured).
    onMounted(() => {
        applyFavicon();
    });

    const removeFinishListener = router.on('finish', () => {
        if (flash.value.success) {
            toast.add({
                severity: 'success',
                summary: trans('sk-layout.success'),
                detail: flash.value.success,
                group: 'bc',
                life: 4000,
            });
        }
        if (flash.value.error) {
            toast.add({
                severity: 'error',
                summary: trans('sk-layout.error'),
                detail: flash.value.error,
                group: 'bc',
                life: 6000,
            });
        }
        if (flash.value.warning) {
            toast.add({
                severity: 'warn',
                summary: trans('sk-layout.warning'),
                detail: flash.value.warning,
                group: 'bc',
                life: 5000,
            });
        }
        if (flash.value.info) {
            toast.add({
                severity: 'info',
                summary: trans('sk-layout.info'),
                detail: flash.value.info,
                group: 'bc',
                life: 4000,
            });
        }
    });

    onUnmounted(() => {
        removeFinishListener();
    });
</script>

<template>
  <Head :title="title" />
  <AppShell>
    <!-- Sidebar -->
    <template #sidebar="{ collapsed, mobileOpen, isMobile, closeMobile }">
      <AdminSidebar
        :collapsed="collapsed"
        :mobile-open="mobileOpen"
        :is-mobile="isMobile"
        @close-mobile="closeMobile"
      />
    </template>

    <!-- Header -->
    <template #header="{ collapsed, isMobile, toggle }">
      <AdminHeader
        :collapsed="collapsed"
        :is-mobile="isMobile"
        :is-dark="isDark"
        :accent="accent"
        :sidebar-style="sidebarStyle"
        @toggle-sidebar="toggle"
        @toggle-dark="toggleDark"
        @set-accent="setAccent"
        @set-sidebar-style="setSidebarStyle"
      />
    </template>

    <!-- Content -->
    <component
      :is="renderPageHeader"
      v-if="renderPageHeaderInPlace"
    />

    <slot />

    <!-- Footer -->
    <template #footer>
      <AdminFooter />
    </template>

    <!-- Global Overlays -->
    <template #overlays>
      <ConfirmDialogComponent />
      <ToastComponent />
      <AppDialog />
      <ImageLightbox />
    </template>
  </AppShell>
</template>
