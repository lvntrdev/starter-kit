<!-- resources/js/layouts/AdminLayout.vue -->
<script setup lang="ts">
    import { useAccentColor } from '@/composables/useAccentColor';
    import { useAppearanceDefaults } from '@/composables/useAppearanceDefaults';
    import { useDarkMode } from '@/composables/useDarkMode';
    import { useTheme } from '@/composables/useTheme';
    import { PAGE_HEADER_KEY } from '@/composables/usePageHeader';
    import { useFlash } from '@/composables/useFlash';
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
        /**
         * Aura: geri-butonlu sayfalarda başlığı ayrı page-header yerine içeriğin
         * ilk kartının başlığına gömer (sayfa `usePageHeader()` ile tüketir).
         */
        headerInCard?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        title: '',
        subtitle: '',
        backUrl: false,
        headerInCard: false,
    });

    const { isDark, toggleDark } = useDarkMode();
    const { accent, setAccent, sidebarStyle, setSidebarStyle } = useAccentColor();
    const { applyFavicon } = useAppearanceDefaults();
    const { theme } = useTheme();

    // Aura, başlığı üst bara (topbar) taşır; diğer temalarda klasik
    // AdminPageHeader bloğu içerir kalır.
    const isAura = computed(() => theme.value === 'aura');
    const hasBack = computed(() => props.backUrl !== false && props.backUrl !== '');

    // Aura'da başlık HER sayfada topbar'da durur (geri butonu olsun olmasın).
    const showTitleInTopbar = computed(() => isAura.value && !!props.title);
    // Aura + geri butonu VAR + başlık topbar'da → geri butonu da topbar'a, başlığın
    // hemen SOLUNA gider. Aura'nın varsayılan geri-butonu konumu budur.
    const backInTopbar = computed(() => showTitleInTopbar.value && hasBack.value);
    // Aura + geri butonu VAR + sayfa opt-in → ilk kart geri butonunu host eder
    // (başlık topbar'da; kart kendi başlığını korur). Topbar geri butonunu
    // host ettiğinde devre dışı kalır — tek bir geri afordansı kalsın.
    const titleInCard = computed(
        () => isAura.value && hasBack.value && props.headerInCard && !backInTopbar.value,
    );
    const slots = useSlots();
    const hasPageActions = computed(() => !!slots['page-actions']);
    // AdminPageHeader bloğu: non-aura'da her zaman; aura'da yalnızca geri butonu
    // varken VE karta gömülmüyorken gösterilir. Geri butonu topbar'a taşındığında
    // blok yalnızca sayfa aksiyonları için ayakta kalır (aksiyon yoksa hiç çizilmez).
    const showPageHeader = computed(() => {
        if (!isAura.value) return true;
        if (!hasBack.value) return false;
        if (props.headerInCard) return false;
        return !backInTopbar.value || hasPageActions.value;
    });

    function goBack() {
        if (props.backUrl === true) {
            window.history.back();
        } else if (typeof props.backUrl === 'string') {
            router.visit(props.backUrl);
        }
    }

    // İlk-kart tüketicileri için sağla: aura geri-butonlu form sayfalarında
    // `active` true olur → kart, geri butonunu kendi aksiyon alanında gösterir
    // (başlık topbar'da kaldığı için kart başlığını ezmez).
    provide(PAGE_HEADER_KEY, reactive({
        active: titleInCard,
        title: computed(() => props.title),
        subtitle: computed(() => props.subtitle),
        goBack,
    }));
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
        :page-title="showTitleInTopbar ? title : ''"
        :page-subtitle="showTitleInTopbar ? subtitle : ''"
        :show-back="backInTopbar"
        @back="goBack"
        @toggle-sidebar="toggle"
        @toggle-dark="toggleDark"
        @set-accent="setAccent"
        @set-sidebar-style="setSidebarStyle"
      />
    </template>

    <!-- Content -->
    <AdminPageHeader
      v-if="showPageHeader"
      :title="title"
      :subtitle="subtitle"
      :back-url="backInTopbar ? false : backUrl"
      :hide-title="showTitleInTopbar"
    >
      <template #actions>
        <slot name="page-actions" />
      </template>
    </AdminPageHeader>

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
