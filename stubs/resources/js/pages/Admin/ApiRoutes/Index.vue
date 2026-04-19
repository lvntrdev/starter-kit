<script setup lang="ts">
    import { Head } from '@inertiajs/vue3';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import { trans } from 'laravel-vue-i18n';
    import { useApi } from '@/composables/useApi';
    import apiRoutes from '@/routes/api-routes';
    import { useToast } from 'primevue/usetoast';

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
    }

    defineProps<Props>();

    const api = useApi();
    const toast = useToast();
    const regenerating = ref(false);

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

    const methodColors: Record<string, string> = {
        GET: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-400',
        POST: 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-400',
        PUT: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-400',
        PATCH: 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-400',
        DELETE: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-400',
    };

    function getMethodClass(method: string): string {
        return (
            methodColors[method.split('|')[0]] ??
            'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300'
        );
    }
</script>

<template>
    <Head :title="$t('sk-api-route.title')" />

    <AdminLayout :title="$t('sk-api-route.title')" :subtitle="$t('sk-api-route.subtitle')">
        <template #page-actions>
            <div class="flex gap-2">
                <Button
                    :label="$t('sk-api-route.regenerate_docs')"
                    icon="pi pi-sync"
                    outlined
                    severity="warn"
                    :loading="regenerating"
                    @click="regenerateDocs"
                />
                <a href="/docs/api" target="_blank" rel="noopener noreferrer">
                    <Button :label="$t('sk-api-route.open_api_docs')" icon="pi pi-book" outlined />
                </a>
            </div>
        </template>

        <div class="space-y-6">
            <!-- API Endpoints -->
            <Card>
                <template #title>
                    {{ $t('sk-api-route.api_endpoints') }}
                </template>
                <template #subtitle>
                    {{ $t('sk-api-route.api_endpoints_subtitle') }}
                </template>
                <template #content>
                    <div v-if="routes.api.length === 0" class="text-sm text-surface-500">
                        {{ $t('sk-api-route.no_routes') }}
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-surface-200 dark:border-surface-700">
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.method') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.uri') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.name') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.action') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.middleware') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(route, index) in routes.api"
                                    :key="index"
                                    class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/50"
                                >
                                    <td class="px-3 py-2">
                                        <span
                                            v-for="m in route.method.split('|')"
                                            :key="m"
                                            class="mr-1 inline-block rounded px-2 py-0.5 text-xs font-bold"
                                            :class="getMethodClass(m)"
                                        >
                                            {{ m }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs text-surface-700 dark:text-surface-300">
                                        {{ route.uri }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-surface-500">
                                        {{ route.name ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs text-surface-600 dark:text-surface-400">
                                        {{ route.action }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-for="mw in route.middleware"
                                            :key="mw"
                                            class="mr-1 mb-1 inline-block rounded-full bg-surface-100 px-2 py-0.5 text-xs text-surface-600 dark:bg-surface-800 dark:text-surface-400"
                                        >
                                            {{ mw }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </Card>

            <!-- Service Endpoints -->
            <Card>
                <template #title>
                    {{ $t('sk-api-route.service_endpoints') }}
                </template>
                <template #subtitle>
                    {{ $t('sk-api-route.service_endpoints_subtitle') }}
                </template>
                <template #content>
                    <div v-if="routes.service.length === 0" class="text-sm text-surface-500">
                        {{ $t('sk-api-route.no_routes') }}
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-surface-200 dark:border-surface-700">
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.method') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.uri') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.name') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.action') }}
                                    </th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-surface-500">
                                        {{ $t('sk-api-route.middleware') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(route, index) in routes.service"
                                    :key="index"
                                    class="border-b border-surface-100 dark:border-surface-800 hover:bg-surface-50 dark:hover:bg-surface-800/50"
                                >
                                    <td class="px-3 py-2">
                                        <span
                                            v-for="m in route.method.split('|')"
                                            :key="m"
                                            class="mr-1 inline-block rounded px-2 py-0.5 text-xs font-bold"
                                            :class="getMethodClass(m)"
                                        >
                                            {{ m }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs text-surface-700 dark:text-surface-300">
                                        {{ route.uri }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-surface-500">
                                        {{ route.name ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs text-surface-600 dark:text-surface-400">
                                        {{ route.action }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            v-for="mw in route.middleware"
                                            :key="mw"
                                            class="mr-1 mb-1 inline-block rounded-full bg-surface-100 px-2 py-0.5 text-xs text-surface-600 dark:bg-surface-800 dark:text-surface-400"
                                        >
                                            {{ mw }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </Card>
        </div>
    </AdminLayout>
</template>
