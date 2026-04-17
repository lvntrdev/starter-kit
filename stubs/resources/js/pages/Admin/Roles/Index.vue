<script setup lang="ts">
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { useCan } from '@/composables/useCan';
    import { useConfirm } from '@/composables/useConfirm';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import roles from '@/routes/roles';
    import { Link, router } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import { Button } from 'primevue';

    interface Props {
        protectedRoles: string[];
        isSystemAdmin: boolean;
        userMinSortOrder: number;
    }

    interface Role {
        id: number;
        name: string;
        sort_order: number;
        permissions_count: number;
        users_count: number;
        created_at: string;
    }

    const props = defineProps<Props>();

    const { confirmDelete } = useConfirm();
    const bus = useRefreshBus();
    const { can } = useCan();

    const REFRESH_KEY = 'roles-table';

    /**
     * Check if the current user can manage the given role based on hierarchy.
     * Users can only manage roles with a higher sort_order (lower rank) than their own.
     */
    function canManageRole(role: Role): boolean {
        if (props.isSystemAdmin) return true;
        return role.sort_order > props.userMinSortOrder;
    }

    // ── Sync Permissions ───────────────────────────────────────────────────────────

    const syncing = ref(false);

    function syncPermissions() {
        syncing.value = true;
        router.post(
            roles.syncPermissions.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () => bus.refresh(REFRESH_KEY),
                onFinish: () => (syncing.value = false),
            },
        );
    }

    // ── Delete ────────────────────────────────────────────────────────────────────

    function deleteRole(role: Role) {
        confirmDelete(
            () => {
                router.delete(roles.destroy.url(role), {
                    onSuccess: () => bus.refresh(REFRESH_KEY),
                });
            },
            trans('sk-role.delete_confirm', { name: role.name }),
        );
    }

    // ── SkDatatable ─────────────────────────────────────────────────────────────────

    const tableConfig = DB.table<Role>()
        .route(roles.dtApi.url())
        .addColumns(
            DB.column<Role>().key('name'),
            DB.column<Role>()
                .label(trans('sk-role.permissions'))
                .key('permissions_count')
                .render(
                    (role) =>
                        `<span class="inline-flex items-center gap-1.5 font-medium"><i class="pi pi-shield text-slate-400"></i>${role.permissions_count}</span>`,
                ),
            DB.column<Role>()
                .label(trans('sk-role.users'))
                .key('users_count')
                .render(
                    (role) =>
                        `<span class="inline-flex items-center gap-1.5 font-medium"><i class="pi pi-users text-slate-400"></i>${role.users_count}</span>`,
                ),
            DB.column<Role>()
                .label(trans('sk-common.created_at'))
                .key('created_at')
                .render((role) =>
                    new Date(role.created_at).toLocaleDateString('tr-TR', {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric',
                    }),
                ),
        )
        .addActions(
            DB.action<Role>()
                .icon('pi pi-pencil')
                .severity('primary')
                .tooltip(trans('sk-common.edit'))
                .visible((role) => can('roles.update') && canManageRole(role))
                .handle((role) => router.visit(roles.edit.url(role))),
            DB.action<Role>()
                .icon('pi pi-trash')
                .severity('danger')
                .tooltip(trans('sk-common.delete'))
                .visible(
                    (role) => can('roles.delete') && !props.protectedRoles.includes(role.name) && canManageRole(role),
                )
                .handle((role) => deleteRole(role)),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('sk-menu.roles_permissions')" :subtitle="$t('sk-role.subtitle')">
        <template v-if="can('roles.create') || isSystemAdmin" #page-actions>
            <Button
                v-if="isSystemAdmin"
                :label="$t('sk-role.sync_permissions')"
                icon="pi pi-sync"
                severity="secondary"
                outlined
                :loading="syncing"
                @click="syncPermissions"
            />
            <Link v-if="can('roles.create')" :href="roles.create.url()">
                <Button :label="$t('sk-role.create')" icon="pi pi-plus" />
            </Link>
        </template>
        <SkDatatable :config="tableConfig" :refresh-key="REFRESH_KEY" />
    </AdminLayout>
</template>
