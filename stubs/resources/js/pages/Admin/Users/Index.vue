<script setup lang="ts">
    import { useCan } from '@/composables/useCan';
    import { useConfirm } from '@/composables/useConfirm';
    import { useDialog } from '@/composables/useDialog';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import { useTheme } from '@/composables/useTheme';
    import { useDatatableSelection } from '@/composables/useDatatableSelection';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import type { User } from '@/types';
    import { router } from '@inertiajs/vue3';
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { trans } from 'laravel-vue-i18n';

    import UserForm from '@/pages/Admin/Users/components/UserForm.vue';
    import users from '@/routes/users';
    import { Button } from 'primevue';

    interface Props {
        roleOptions: { label: string; value: string; color: string | null }[];
        timezones: string[];
    }

    const props = defineProps<Props>();

    const { confirmDelete, confirmAction } = useConfirm();
    const dialog = useDialog();
    const bus = useRefreshBus();
    const { can } = useCan();
    const { theme } = useTheme();

    const REFRESH_KEY = 'users-table';

    // ── Bulk Selection ─────────────────────────────────────────────────────────────

    const selection = useDatatableSelection({
        bulkUrl: users.bulk.url(),
        idKey: 'id',
        onSuccess: () => bus.refresh(REFRESH_KEY),
    });

    // ── Create dialog ─────────────────────────────────────────────────────────────

    function openCreateDialog() {
        dialog.open(
            UserForm,
            { inDialog: true, roleOptions: props.roleOptions, timezones: props.timezones },
            trans('sk-user.create'),
            {
                icon: 'pi pi-user-plus',
                subtitle: 'Yeni kullanıcı oluştur',
                refreshKey: REFRESH_KEY,
            },
        );
    }

    // ── Edit dialog ───────────────────────────────────────────────────────────────

    function openEditDialog(userId: string) {
        dialog.open(
            UserForm,
            { userId, inDialog: true, roleOptions: props.roleOptions, timezones: props.timezones },
            trans('sk-user.edit'),
            {
                icon: 'pi pi-user-edit',
                subtitle: 'Kullanıcı bilgilerini güncelle',
                refreshKey: REFRESH_KEY,
            },
        );
    }

    // ── Delete ────────────────────────────────────────────────────────────────────

    function deleteUser(user: User) {
        confirmDelete(
            () => {
                router.delete(users.destroy.url(user), {
                    onSuccess: () => bus.refresh('users-table'),
                });
            },
            trans('sk-user.delete_confirm', { name: user.full_name }),
        );
    }

    // ── Bulk Delete ───────────────────────────────────────────────────────────────

    /** Filter snapshot — reflects the table's active filters for cross-page selection. */
    const activeFilterSnapshot = ref<Record<string, unknown>>({});

    function confirmBulkDelete(totalFiltered: number) {
        if (!selection.hasSelection.value) return;

        const isAllMode = selection.isAllFilteredMode.value;
        const count = selection.selectedCount.value;

        const message = isAllMode
            ? trans('sk-datatable.bulk_delete_confirm_all', { total: String(totalFiltered) })
            : trans('sk-datatable.bulk_delete_confirm', { count: String(count) });

        confirmAction({
            header: trans('sk-datatable.bulk_delete_header'),
            message,
            icon: 'pi pi-trash',
            acceptLabel: trans('sk-button.delete'),
            acceptClass: 'p-button-danger',
            onAccept: () => {
                selection.executeBulkAction('delete', activeFilterSnapshot.value);
            },
        });
    }

    // ── SkDatatable ─────────────────────────────────────────────────────────────────

    const tableBuilder = DB.table<User>()
        .route(users.dtApi.url())
        .title('sk-menu.users')
        // .searchable(true)
        .sortable(true);
    // .isCard(false)
    // .pagination(true)

    // Create butonu yalnızca `aura` temasında datatable toolbar'ında görünür;
    // diğer temalarda header page-action butonu (template'te) kullanılır.
    if (theme.value === 'aura' && can('users.create')) {
        tableBuilder.create({ onClick: openCreateDialog, label: 'sk-user.create' });
    }

    const tableConfig = tableBuilder
        .addColumns(
            DB.column<User>().label('sk-common.full_name').key('full_name'),
            DB.column<User>().key('email'),
            DB.column<User>()
                .label('sk-common.role')
                .key('role')
                .tag('value')
                .tagLabels(Object.fromEntries(props.roleOptions.map((o) => [o.value, o.label])))
                .tagSeverityKey('role_color')
                .tagSoft(),
            DB.column<User>().key('status').tag('definition').tagKey('userStatus').tagOutlined(),
            DB.column<User>().label('sk-common.created_at').key('created_at'),
        )
        .addFilters(
            // inline() → header'da pill olarak; aynı filtreler filtre popover'ında da listelenir
            DB.filter().key('status').definitionOptions('userStatus').inline(),
            DB.filter().key('role').label('sk-common.role').type('select').options(props.roleOptions).inline(),
            // Tarih aralığı filtresi — hem header'da (inline) hem filtre popover'ında (created_at_from / _to)
            DB.filter().key('created_at').label('sk-common.created_at').type('daterange').inline(),
        )
        .addActions(
            DB.action<User>()
                .icon('pi pi-pencil')
                .severity('warn')
                .label('sk-button.edit')
                .visible(() => can('users.update'))
                .handle((user) => openEditDialog(user.id)),
            DB.action<User>()
                .icon('pi pi-trash')
                .severity('danger')
                .label('sk-button.delete')
                .visible(() => can('users.delete'))
                .handle((user) => deleteUser(user)),
        )
        .build();

    // ── Table ref for total count access ──────────────────────────────────────────
    const tableRef = ref();
    const totalFiltered = ref(0);

    function onTableLoad(_data: unknown[], total: number) {
        // total = meta.total from the API response (all filtered records, not just current page).
        totalFiltered.value = total;

        // Update filter snapshot from the table's current URL params.
        // SkDatatable syncs state to URL on every load, so we parse it here.
        const params = new URLSearchParams(window.location.search);
        const snapshot: Record<string, unknown> = {};
        params.forEach((val, key) => {
            snapshot[key] = val;
        });
        activeFilterSnapshot.value = snapshot;
    }
</script>

<template>
  <AdminLayout
    :title="$t('sk-menu.users')"
    :subtitle="$t('sk-user.subtitle')"
  >
    <template
      v-if="can('users.create') && theme !== 'aura'"
      #page-actions
    >
      <Button
        :label="$t('sk-user.create')"
        icon="pi pi-user-plus"
        @click="openCreateDialog"
      />
    </template>

    <SkDatatable
      ref="tableRef"
      :config="tableConfig"
      :refresh-key="REFRESH_KEY"
      :selection="selection"
      @load="onTableLoad"
    >
      <!-- Floating bulk bar actions — the bar (count label + clear) is built into SkDatatable -->
      <template #bulk-actions>
        <!-- Select all filtered (cross-page) -->
        <Button
          v-if="!selection.isAllFilteredMode.value && totalFiltered > selection.selectedCount.value"
          :label="$t('sk-datatable.bulk_select_all_filtered', { total: String(totalFiltered) })"
          size="small"
          severity="secondary"
          variant="text"
          @click="selection.selectAllFiltered()"
        />

        <!-- Bulk delete -->
        <Button
          v-if="can('users.delete')"
          :label="$t('sk-datatable.bulk_delete')"
          icon="pi pi-trash"
          size="small"
          severity="danger"
          variant="text"
          :loading="selection.submitting.value"
          @click="confirmBulkDelete(totalFiltered)"
        />
      </template>
    </SkDatatable>
  </AdminLayout>
</template>
