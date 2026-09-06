<script setup lang="ts">
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { formatDate } from '@lvntr/components/utils/datetime';
    import { useCan } from '@/composables/useCan';
    import { useConfirm } from '@/composables/useConfirm';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import { useTheme } from '@/composables/useTheme';
    import { useDatatableSelection } from '@/composables/useDatatableSelection';
    import AdminLayout from '@/layouts/AdminLayout.vue';
    import roles from '@/routes/roles';
    import { Link, router } from '@inertiajs/vue3';
    import { trans } from 'laravel-vue-i18n';
    import { Button } from 'primevue';

    interface Props {
        protectedRoles: string[];
        isSystemAdmin: boolean;
        /** null = role-less actor (direct-permission user) — lowest possible rank. */
        userMinSortOrder: number | null;
    }

    interface Role {
        id: number;
        name: string;
        display_name: Record<string, string> | null;
        color: string | null;
        sort_order: number;
        permissions_count: number;
        users_count: number;
        created_at: string;
    }

    const props = defineProps<Props>();

    const { confirmDelete, confirmAction } = useConfirm();
    const bus = useRefreshBus();
    const { can } = useCan();
    const { theme } = useTheme();

    const REFRESH_KEY = 'roles-table';

    /**
     * Check if the current user can manage the given role based on hierarchy.
     * Users can only manage roles with a higher sort_order (lower rank) than their own.
     */
    function canManageRole(role: Role): boolean {
        if (props.isSystemAdmin) return true;
        if (props.userMinSortOrder === null) return false;
        return role.sort_order > props.userMinSortOrder;
    }

    // ── Bulk Selection ─────────────────────────────────────────────────────────────

    const selection = useDatatableSelection({
        bulkUrl: roles.bulk.url(),
        idKey: 'id',
        onSuccess: () => bus.refresh(REFRESH_KEY),
    });

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

    // ── Bulk Delete ───────────────────────────────────────────────────────────────

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

    const tableBuilder = DB.table<Role>().route(roles.dtApi.url()).title('sk-menu.roles_permissions');

    // Create butonu yalnızca `aura` temasında datatable toolbar'ında görünür
    // (URL variant — Link olarak render edilir); diğer temalarda header'daki
    // page-action Link butonu kullanılır.
    if (theme.value === 'aura' && can('roles.create')) {
        tableBuilder.create({ url: roles.create.url(), label: 'sk-role.create', icon: 'pi pi-plus' });
    }

    const tableConfig = tableBuilder
        .addColumns(
            DB.column<Role>().key('name').tag('value').tagSeverityKey('color').tagSoft(),
            DB.column<Role>()
                .label(trans('sk-role.display_name'))
                .key('display_name')
                .render((role, escapeHtml) => {
                    const lang = typeof document === 'undefined' ? 'en' : document.documentElement.lang || 'en';
                    return escapeHtml(role.display_name?.[lang] || role.display_name?.en || '—');
                }),
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
                    formatDate(role.created_at, {
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

    // ── Table ref for total count access ──────────────────────────────────────────
    const totalFiltered = ref(0);

    function onTableLoad(_data: unknown[], total: number) {
        totalFiltered.value = total;
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
    :title="$t('sk-menu.roles_permissions')"
    :subtitle="$t('sk-role.subtitle')"
  >
    <template
      v-if="can('roles.create') || isSystemAdmin"
      #page-actions
    >
      <Button
        v-if="isSystemAdmin && theme !== 'aura'"
        :label="$t('sk-role.sync_permissions')"
        icon="pi pi-sync"
        severity="secondary"
        outlined
        :loading="syncing"
        @click="syncPermissions"
      />
      <Link
        v-if="can('roles.create') && theme !== 'aura'"
        :href="roles.create.url()"
      >
        <Button
          :label="$t('sk-role.create')"
          icon="pi pi-plus"
        />
      </Link>
    </template>

    <SkDatatable
      :config="tableConfig"
      :refresh-key="REFRESH_KEY"
      :selection="selection"
      @load="onTableLoad"
    >
      <!-- İzin senkronizasyon butonu aura temasında datatable toolbar'ında (slot) -->
      <template
        v-if="isSystemAdmin && theme === 'aura'"
        #toolbar-start
      >
        <Button
          :label="$t('sk-role.sync_permissions')"
          icon="pi pi-sync"
          severity="secondary"
          outlined
          :loading="syncing"
          @click="syncPermissions"
        />
      </template>

      <!-- Floating bulk bar actions — the bar (count label + clear) is built into SkDatatable -->
      <template #bulk-actions>
        <Button
          v-if="!selection.isAllFilteredMode.value && totalFiltered > selection.selectedCount.value"
          :label="$t('sk-datatable.bulk_select_all_filtered', { total: String(totalFiltered) })"
          size="small"
          severity="secondary"
          variant="text"
          @click="selection.selectAllFiltered()"
        />

        <Button
          v-if="can('roles.delete')"
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
