<script setup lang="ts">
    /**
     * Content Languages settings tab — datatable + create/edit dialog.
     *
     * Content languages drive the locale tabs of translatable content fields
     * (TranslatableInput), a separate concept from the admin UI locale. CRUD goes
     * through the JSON ApiResponse endpoints (settings.contentLanguages.*); the
     * form is rendered in a dialog via useDialog.
     *
     * Permissions: content languages are a setting — no dedicated permission.
     * Whoever can read Settings sees this tab; writes follow settings.update.
     *
     * Default-language guard (UX mirror of the backend invariant): the default
     * language cannot be deleted — its delete action is hidden for that row — and
     * its `is_default` toggle is locked in the edit form. The backend re-enforces
     * both, so this is purely a friendlier surface, not the security boundary.
     */
    import { useApi } from '@/composables/useApi';
    import { useCan } from '@/composables/useCan';
    import { useConfirm } from '@/composables/useConfirm';
    import { useDialog } from '@/composables/useDialog';
    import { useRefreshBus } from '@/composables/useRefreshBus';
    import { DB } from '@lvntr/components/DatatableBuilder/core';
    import { trans } from 'laravel-vue-i18n';
    import { useToast } from 'primevue/usetoast';

    import ContentLanguageForm from './ContentLanguageForm.vue';
    import adminSettings from '@/routes/settings';

    interface ContentLanguage {
        id: number;
        code: string;
        name: string;
        native_name: string;
        direction: 'ltr' | 'rtl';
        flag: string | null;
        is_active: boolean;
        is_default: boolean;
        fallback_code: string | null;
        sort_order: number;
    }

    const api = useApi();
    const toast = useToast();
    const { confirmDelete } = useConfirm();
    const dialog = useDialog();
    const bus = useRefreshBus();
    const { can } = useCan();

    const REFRESH_KEY = 'content-languages-table';

    function openEditDialog(language: ContentLanguage) {
        dialog.open(
            ContentLanguageForm,
            { languageId: language.id, isDefault: language.is_default, inDialog: true },
            trans('sk-content-languages.edit'),
            { width: '640px', icon: 'pi pi-pencil', refreshKey: REFRESH_KEY },
        );
    }

    function deleteLanguage(language: ContentLanguage) {
        confirmDelete(async () => {
            try {
                await api.delete(adminSettings.contentLanguages.remove.url(language.id));
                toast.add({
                    severity: 'success',
                    summary: trans('sk-content-languages.title'),
                    detail: trans('sk-message.deleted', { entity: trans('sk-content-languages.entity') }),
                    group: 'bc',
                    life: 3000,
                });
                bus.refresh(REFRESH_KEY);
            } catch {
                // useApi already surfaced the 422 / error toast (e.g. delete_default guard).
            }
        }, trans('sk-content-languages.delete_confirm', { name: language.name }));
    }

    const tableBuilder = DB.table<ContentLanguage>()
        .route(adminSettings.contentLanguages.dt.url())
        .isCard(true)
        .title('sk-content-languages.title')
        .subtitle('sk-content-languages.subtitle')
        .searchable(true)
        .sortable(true)
        .addColumns(
            DB.column<ContentLanguage>()
                .label('sk-content-languages.fields.code')
                .key('code')
                .render(
                    (row, escape) =>
                        `<code class="font-mono text-base text-surface-600 dark:text-surface-400">${escape(row.code)}</code>`,
                ),
            DB.column<ContentLanguage>().label('sk-content-languages.fields.name').key('name'),
            DB.column<ContentLanguage>()
                .label('sk-content-languages.fields.native_name')
                .key('native_name'),
            DB.column<ContentLanguage>()
                .label('sk-content-languages.fields.direction')
                .key('direction')
                .tag('value')
                .tagLabels({
                    ltr: 'sk-content-languages.directions.ltr',
                    rtl: 'sk-content-languages.directions.rtl',
                })
                .colors({ ltr: 'blue', rtl: 'purple' })
                .tagSoft(),
            DB.column<ContentLanguage>()
                .label('sk-content-languages.fields.is_active')
                .key('is_active')
                .tag('value')
                .tagLabels({
                    true: 'sk-content-languages.active',
                    false: 'sk-content-languages.inactive',
                })
                .colors({ true: 'emerald', false: 'slate' })
                .tagSoft(),
            DB.column<ContentLanguage>()
                .label('sk-content-languages.fields.is_default')
                .key('is_default')
                .tag('value')
                .tagLabels({
                    true: 'sk-content-languages.default',
                    false: '—',
                })
                .colors({ true: 'amber' })
                .tagSoft(),
            DB.column<ContentLanguage>()
                .label('sk-content-languages.fields.sort_order')
                .key('sort_order'),
        )
        .addFilters(
            DB.filter()
                .key('is_active')
                .label('sk-content-languages.fields.is_active')
                .type('select')
                .options([
                    { label: trans('sk-content-languages.active'), value: '1' },
                    { label: trans('sk-content-languages.inactive'), value: '0' },
                ])
                .inline(),
        )
        .addActions(
            DB.action<ContentLanguage>()
                .icon('pi pi-pencil')
                .severity('warn')
                .tooltip(trans('sk-button.edit'))
                .visible(() => can('settings.update'))
                .handle((language) => openEditDialog(language)),
            DB.action<ContentLanguage>()
                .icon('pi pi-trash')
                .severity('danger')
                .tooltip(trans('sk-button.delete'))
                // Default language cannot be deleted (backend last_default guard) — hide the action.
                .visible((language) => can('settings.update') && !language.is_default)
                .handle((language) => deleteLanguage(language)),
        );

    if (can('settings.update')) {
        tableBuilder.create({
            label: 'sk-content-languages.create',
            onClick: () =>
                dialog.open(
                    ContentLanguageForm,
                    { inDialog: true },
                    trans('sk-content-languages.create'),
                    { width: '640px', icon: 'pi pi-plus', refreshKey: REFRESH_KEY },
                ),
        });
    }

    const tableConfig = tableBuilder.build();
</script>

<template>
    <SkDatatable :config="tableConfig" :refresh-key="REFRESH_KEY" />
</template>
