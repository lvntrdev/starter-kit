<script setup lang="ts">
    import { computed, ref } from 'vue';
    import { Head } from '@inertiajs/vue3';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { trans } from 'laravel-vue-i18n';
    import { useApi } from '@/composables/useApi';
    import { useCan } from '@/composables/useCan';
    import { useDialog } from '@/composables/useDialog';
    import apiRoutes from '@/routes/api-routes';
    import { useToast } from 'primevue/usetoast';
    import ApiIntegrationsDialog from './components/ApiIntegrationsDialog.vue';
    import ApiRoutesActions from './components/ApiRoutesActions.vue';
    import SkCard from '@lvntr/components/ui/SkCard.vue';

    interface RouteItem {
        method: string;
        uri: string;
        name: string | null;
        action: string;
        middleware: string[];
    }

    interface Props {
        routes: {
            api: RouteItem[];
            service: RouteItem[];
        };
        postman: {
            configured: boolean;
            workspace_id: string | null;
            api_key_is_set: boolean;
        };
        apidog: {
            configured: boolean;
            project_id: string | null;
            access_token_is_set: boolean;
        };
        /** Resolved from the `api-dock.docs` route; null when api-dock is absent or disabled. */
        api_docs_url?: string | null;
    }

    const props = defineProps<Props>();

    const api = useApi();
    const toast = useToast();
    const dialog = useDialog();
    const { can } = useCan();

    const regenerating = ref(false);
    const syncingPostman = ref(false);
    const syncingApidog = ref(false);

    async function regenerateDocs(): Promise<void> {
        regenerating.value = true;
        try {
            await api.post(apiRoutes.regenerateDocs.url());
            toast.add({
                severity: 'success',
                summary: trans('sk-api-route.regenerate_docs_success'),
                group: 'bc',
                life: 3000,
            });
        } finally {
            regenerating.value = false;
        }
    }

    async function syncPostman(): Promise<void> {
        if (!props.postman.configured) {
            toast.add({
                severity: 'warn',
                summary: trans('sk-api-route.sync_postman_not_configured'),
                group: 'bc',
                life: 4000,
            });
            if (can('settings.update')) openIntegrations();
            return;
        }

        syncingPostman.value = true;
        try {
            await api.post(apiRoutes.syncPostman.url());
            toast.add({
                severity: 'success',
                summary: trans('sk-api-route.sync_postman_success'),
                group: 'bc',
                life: 3000,
            });
        } finally {
            syncingPostman.value = false;
        }
    }

    async function syncApidog(): Promise<void> {
        if (!props.apidog.configured) {
            toast.add({
                severity: 'warn',
                summary: trans('sk-api-route.sync_apidog_not_configured'),
                group: 'bc',
                life: 4000,
            });
            if (can('settings.update')) openIntegrations();
            return;
        }

        syncingApidog.value = true;
        try {
            await api.post(apiRoutes.syncApidog.url());
            toast.add({
                severity: 'success',
                summary: trans('sk-api-route.sync_apidog_success'),
                group: 'bc',
                life: 3000,
            });
        } finally {
            syncingApidog.value = false;
        }
    }

    function openIntegrations(): void {
        dialog.open(
            ApiIntegrationsDialog,
            { postman: props.postman, apidog: props.apidog },
            trans('sk-api-route.integrations.title'),
            {
                icon: 'pi pi-cog',
                subtitle: trans('sk-api-route.integrations.subtitle'),
                width: '560px',
                footer: {
                    hideConfirm: true,
                    cancelLabel: trans('sk-button.close'),
                },
            },
        );
    }

    // ── Tabs + per-tab search ───────────────────────────────────────────
    const activeTab = ref<'api' | 'service'>('api');
    const apiSearch = ref('');
    const serviceSearch = ref('');

    const search = computed({
        get: () => (activeTab.value === 'api' ? apiSearch.value : serviceSearch.value),
        set: (val: string) => {
            if (activeTab.value === 'api') apiSearch.value = val;
            else serviceSearch.value = val;
        },
    });

    function filterRoutes(routes: RouteItem[], query: string): RouteItem[] {
        const q = query.trim().toLowerCase();
        if (!q) return routes;

        return routes.filter(
            (r) =>
                r.uri.toLowerCase().includes(q) ||
                (r.name ?? '').toLowerCase().includes(q) ||
                r.action.toLowerCase().includes(q) ||
                r.method.toLowerCase().includes(q),
        );
    }

    const filteredRoutes = computed(() =>
        activeTab.value === 'api'
            ? filterRoutes(props.routes.api, apiSearch.value)
            : filterRoutes(props.routes.service, serviceSearch.value),
    );

    // ── Cell rendering helpers ──────────────────────────────────────────
    const methodBadgeClasses: Record<string, string> = {
        GET: 'border-green-600/25 bg-green-600/10 text-green-700 dark:text-green-400',
        POST: 'border-[color-mix(in_srgb,var(--p-primary-color)_25%,transparent)] bg-[color-mix(in_srgb,var(--p-primary-color)_12%,transparent)] text-[var(--p-primary-color)]',
        PUT: 'border-amber-600/25 bg-amber-600/10 text-amber-700 dark:text-amber-400',
        PATCH: 'border-amber-600/25 bg-amber-600/10 text-amber-700 dark:text-amber-400',
        DELETE: 'border-red-600/25 bg-red-600/10 text-red-700 dark:text-red-400',
    };

    function methodBadgeClass(method: string): string {
        return (
            methodBadgeClasses[method] ??
            'border-surface-200 bg-surface-50 text-surface-600 dark:border-surface-700 dark:bg-surface-800 dark:text-surface-300'
        );
    }

    /** Split a URI into static and `{param}` segments for two-tone rendering. */
    function uriParts(uri: string): { text: string; param: boolean }[] {
        return uri
            .split(/(\{[^}]+\})/)
            .filter(Boolean)
            .map((s) => ({ text: s, param: s.startsWith('{') }));
    }

    /** Split `Controller@method` for muted-@ rendering. */
    function actionParts(action: string): { controller: string; method: string | null } {
        const at = action.indexOf('@');
        if (at === -1) return { controller: action, method: null };

        return { controller: action.slice(0, at), method: action.slice(at + 1) };
    }

    /** Auth / permission gates render in the accent color. */
    function isAccentMiddleware(mw: string): boolean {
        return /^(auth|verified|check\.permission)/.test(mw);
    }

    const tabs = computed(() => [
        {
            key: 'api' as const,
            label: trans('sk-api-route.api_endpoints'),
            icon: 'pi pi-cloud',
            count: props.routes.api.length,
            subtitle: trans('sk-api-route.api_endpoints_subtitle'),
        },
        {
            key: 'service' as const,
            label: trans('sk-api-route.service_endpoints'),
            icon: 'pi pi-server',
            count: props.routes.service.length,
            subtitle: trans('sk-api-route.service_endpoints_subtitle'),
        },
    ]);

    const activeSubtitle = computed(
        () => tabs.value.find((t) => t.key === activeTab.value)?.subtitle ?? '',
    );
</script>

<template>
    <Head :title="$t('sk-api-route.title')" />

    <AdminLayout :title="$t('sk-api-route.title')" :subtitle="$t('sk-api-route.subtitle')">
        <template #page-actions>
            <ApiRoutesActions
                :api-docs-url="props.api_docs_url"
                :regenerating="regenerating"
                :syncing-postman="syncingPostman"
                :syncing-apidog="syncingApidog"
                @settings="openIntegrations"
                @regenerate="regenerateDocs"
                @sync-postman="syncPostman"
                @sync-apidog="syncApidog"
            />
        </template>

        <!-- SkCard rather than a hand-rolled surface: aura hosts the page header in
             the first card's head, so every admin screen keeps it in the same place. -->
        <SkCard flush>
            <!-- Tab bar -->
            <div class="flex gap-0.5 border-b border-surface-200 dark:border-surface-700">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="relative inline-flex h-14 items-center gap-2 px-5 text-[13.5px] font-semibold whitespace-nowrap transition-colors first:pl-6"
                    :class="
                        activeTab === tab.key
                            ? 'text-[var(--p-primary-color)]'
                            : 'text-surface-500 hover:text-surface-900 dark:text-surface-400 dark:hover:text-surface-0'
                    "
                    @click="activeTab = tab.key"
                >
                    <i :class="tab.icon" class="text-sm" />
                    {{ tab.label }}
                    <span
                        class="inline-grid h-[22px] min-w-6 place-items-center rounded-full border border-surface-200 bg-surface-50 px-2 text-[11.5px] font-semibold text-surface-500 tabular-nums dark:border-surface-700 dark:bg-surface-800 dark:text-surface-400"
                    >
                        {{ tab.count }}
                    </span>
                    <span
                        v-if="activeTab === tab.key"
                        class="absolute inset-x-0 -bottom-px h-[3px] bg-[var(--p-primary-color)]"
                    />
                </button>
            </div>

            <!-- Panel head: subtitle + search -->
            <div
                class="flex flex-wrap items-center gap-3.5 border-b border-surface-200 px-6 py-4 dark:border-surface-700"
            >
                <p class="text-[12.5px] text-surface-500 dark:text-surface-400">
                    {{ activeSubtitle }}
                </p>
                <div
                    class="ml-auto flex h-9 w-[232px] max-w-[46vw] items-center gap-2 rounded-md border border-surface-200 bg-surface-50 px-3 transition-colors focus-within:border-[var(--p-primary-color)] dark:border-surface-700 dark:bg-surface-800"
                >
                    <i class="pi pi-search text-[13px] text-surface-400 dark:text-surface-500" />
                    <input
                        v-model="search"
                        type="text"
                        :placeholder="$t('sk-api-route.search_placeholder')"
                        class="w-full min-w-0 flex-1 border-0 bg-transparent text-[13px] text-surface-900 outline-none placeholder:text-surface-400 dark:text-surface-0 dark:placeholder:text-surface-500"
                    />
                </div>
            </div>

            <!-- Routes table -->
            <div class="overflow-x-auto">
                <table v-if="filteredRoutes.length" class="w-full text-sm">
                    <thead
                        class="border-b border-surface-200 bg-surface-50 dark:border-surface-700 dark:bg-surface-800/50"
                    >
                        <tr
                            class="text-left text-xs font-semibold tracking-wider text-surface-500 uppercase dark:text-surface-400"
                        >
                            <th class="px-4 py-3 pl-6">{{ $t('sk-api-route.method') }}</th>
                            <th class="px-4 py-3">{{ $t('sk-api-route.uri') }}</th>
                            <th class="px-4 py-3">{{ $t('sk-api-route.name') }}</th>
                            <th class="px-4 py-3">{{ $t('sk-api-route.action') }}</th>
                            <th class="w-[38%] px-4 py-3">{{ $t('sk-api-route.middleware') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(route, index) in filteredRoutes"
                            :key="index"
                            class="border-b border-surface-100 transition-colors last:border-0 hover:bg-surface-50 dark:border-surface-800 dark:hover:bg-surface-800/50"
                        >
                            <td class="px-4 py-3 pl-6 align-middle">
                                <span class="inline-flex flex-wrap items-center gap-1.5">
                                    <span
                                        v-for="m in route.method.split('|')"
                                        :key="m"
                                        class="inline-flex h-[22px] items-center rounded-md border px-2 font-mono text-[10.5px] font-bold tracking-wide whitespace-nowrap"
                                        :class="methodBadgeClass(m)"
                                    >
                                        {{ m }}
                                    </span>
                                </span>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <span class="font-mono text-[12.5px] whitespace-nowrap">
                                    <span
                                        v-for="(part, i) in uriParts(route.uri)"
                                        :key="i"
                                        :class="
                                            part.param
                                                ? 'font-semibold text-[var(--p-primary-color)]'
                                                : 'text-surface-500 dark:text-surface-400'
                                        "
                                        >{{ part.text }}</span
                                    >
                                </span>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <span
                                    class="font-mono text-xs whitespace-nowrap text-surface-500 dark:text-surface-400"
                                >
                                    {{ route.name ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <span
                                    class="font-mono text-[12.5px] whitespace-nowrap text-surface-600 dark:text-surface-300"
                                >
                                    {{ actionParts(route.action).controller
                                    }}<template v-if="actionParts(route.action).method !== null"
                                        ><span class="text-surface-400 dark:text-surface-500">@</span
                                        >{{ actionParts(route.action).method }}</template
                                    >
                                </span>
                            </td>
                            <td class="px-4 py-3 align-middle">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    <span
                                        v-for="mw in route.middleware"
                                        :key="mw"
                                        class="inline-flex h-[21px] items-center rounded-md border px-2 font-mono text-[10.75px] whitespace-nowrap"
                                        :class="
                                            isAccentMiddleware(mw)
                                                ? 'border-[color-mix(in_srgb,var(--p-primary-color)_22%,transparent)] bg-[color-mix(in_srgb,var(--p-primary-color)_10%,transparent)] text-[var(--p-primary-color)]'
                                                : 'border-surface-200 bg-surface-50 text-surface-500 dark:border-surface-700 dark:bg-surface-800 dark:text-surface-400'
                                        "
                                    >
                                        {{ mw }}
                                    </span>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Empty state -->
                <div v-else class="flex flex-col items-center gap-2 px-6 py-16">
                    <i class="pi pi-share-alt text-3xl text-surface-300 dark:text-surface-600" />
                    <p class="text-sm font-semibold text-surface-700 dark:text-surface-200">
                        {{ $t('sk-api-route.no_matches') }}
                    </p>
                    <p class="text-[13px] text-surface-500 dark:text-surface-400">
                        {{ $t('sk-api-route.no_matches_hint') }}
                    </p>
                </div>
            </div>
        </SkCard>
    </AdminLayout>
</template>
