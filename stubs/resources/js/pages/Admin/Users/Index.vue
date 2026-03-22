<script setup lang="ts">
    import { useCan } from '@/composables/useCan';
    import { useConfirm } from '@/composables/useConfirm';
    import { useDialog } from '@/composables/useDialog';
    import { useDefinition } from '@/composables/useDefinition';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import type { User } from '@/types';
    import { router } from '@inertiajs/vue3';
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { trans } from 'laravel-vue-i18n';

    import UserForm from '@/pages/Admin/Users/components/UserForm.vue';
    import users from '@/routes/users';
    import { Button } from 'primevue';

    interface Props {
        roleOptions: { label: string; value: string }[];
    }

    const props = defineProps<Props>();

    const { confirmDelete } = useConfirm();
    const dialog = useDialog();
    const bus = useRefreshBus();
    const { options, load: loadDefinitions } = useDefinition();

    onMounted(() => loadDefinitions(['userStatus']));
    const { can } = useCan();

    const REFRESH_KEY = 'users-table';

    // ── Create dialog ─────────────────────────────────────────────────────────────

    function openCreateDialog() {
        dialog.open(UserForm, { inDialog: true }, trans('admin.users.create'), {
            refreshKey: REFRESH_KEY,
        });
    }

    // ── Edit dialog ───────────────────────────────────────────────────────────────

    function openEditDialog(userId: string) {
        dialog.open(UserForm, { userId, inDialog: true }, trans('admin.users.edit'), {
            refreshKey: REFRESH_KEY,
        });
    }

    // ── Delete ────────────────────────────────────────────────────────────────────

    function deleteUser(user: User) {
        confirmDelete(
            () => {
                router.delete(users.destroy.url(user), {
                    onSuccess: () => bus.refresh('users-table'),
                });
            },
            trans('admin.users.delete_confirm', { name: user.full_name }),
        );
    }

    // ── SkDatatable ─────────────────────────────────────────────────────────────────

    const tableConfig = DB.table<User>()
        .route(users.dtApi.url())
        // .searchable(true)
        .sortable(true)
        // .pagination(true)
        // .create({ onClick: openCreateDialog })
        .addColumns(
            DB.column<User>().label('common.full_name').key('full_name'),
            DB.column<User>().key('email'),
            DB.column<User>().label('common.role').key('role'),
            DB.column<User>().key('status').tag('definition').tagKey('userStatus'),
            DB.column<User>().label('common.created_at').key('created_at'),
        )
        .addFilters(
            DB.filter().key('status').type('select').options(options('userStatus')),
            DB.filter().key('role').label('common.role').type('select').options(props.roleOptions),
        )
        .addActions(
            /*   DB.action<User>()
                .icon('pi pi-eye')
                .tooltip('button.view')
                .handle((user) => router.visit(users.show.url(user))), */
            DB.action<User>()
                .icon('pi pi-pencil')
                .severity('warn')
                .label('button.edit')
                .visible(() => can('users.update'))
                .handle((user) => openEditDialog(user.id)),
        )
        .addMenuActions(
            /*  DB.menuAction<User>()
                .label('button.view')
                .icon('pi pi-eye')
                .handle((user) => router.visit(users.show.url(user))), */
            DB.menuAction<User>()
                .label('button.edit')
                .icon('pi pi-pencil')
                .visible(() => can('users.update'))
                .handle((user) => openEditDialog(user.id)),
            DB.menuAction<User>()
                .label('button.edit_on_page')
                .icon('pi pi-external-link')
                .visible(() => can('users.update'))
                .handle((user) => router.visit(users.edit.url(user))),
            DB.menuAction<User>()
                .label('button.delete')
                .icon('pi pi-trash')
                .separator()
                .visible(() => can('users.delete'))
                .handle((user) => deleteUser(user)),
        )
        .build();
</script>

<template>
    <AdminLayout :title="$t('admin.menu.users')" :subtitle="$t('admin.users.subtitle')">
        <template v-if="can('users.create')" #page-actions>
            <Button :label="$t('admin.users.create')" icon="pi pi-user-plus" @click="openCreateDialog" />
        </template>
        <SkDatatable :config="tableConfig" :refresh-key="REFRESH_KEY">
            <!-- <template #toolbar>
                <Button
                                label="Ekle"
                                icon="pi pi-plus"
                                severity="success"
                                @click="openCreateDialog"
                            />
                <Link :href="admin.users.create()">
                    <Button label="New User" icon="pi pi-user-plus" outlined />
                </Link>
            </template> -->
        </SkDatatable>
    </AdminLayout>
</template>
