// resources/js/composables/useConfirm.ts

import { useConfirm as usePrimeConfirm } from 'primevue/useconfirm';

/**
 * Composable for confirmation dialogs using PrimeVue ConfirmDialog.
 * Wraps PrimeVue's useConfirm with English defaults and simpler API.
 *
 * Requirements:
 *   - <ConfirmDialog /> must be placed in the layout.
 *   - ConfirmationService must be registered in app.ts.
 *
 * Usage:
 *   const { confirmDelete, confirmAction } = useConfirm();
 *   confirmDelete(() => router.delete(`/admin/users/${id}`));
 */
export function useConfirm() {
    const confirm = usePrimeConfirm();

    /**
     * Show a delete confirmation dialog.
     */
    function confirmDelete(onAccept: () => void, message?: string, icon?: string) {
        confirm.require({
            group: 'app',
            message: message ?? 'Are you sure you want to delete this record? This action cannot be undone.',
            header: 'Delete Confirmation',
            icon: icon ?? 'pi pi-trash',
            rejectLabel: 'Cancel',
            acceptLabel: 'Delete',
            rejectClass: 'p-button-secondary p-button-outlined',
            acceptClass: 'p-button-danger',
            accept: onAccept,
        });
    }

    /**
     * Show a generic confirmation dialog.
     */
    function confirmAction(options: {
        message: string;
        header?: string;
        icon?: string;
        onAccept: () => void;
        onReject?: () => void;
        acceptLabel?: string;
        rejectLabel?: string;
        acceptClass?: string;
    }) {
        confirm.require({
            group: 'app',
            message: options.message,
            header: options.header ?? 'Confirmation',
            icon: options.icon ?? 'pi pi-question-circle',
            rejectLabel: options.rejectLabel ?? 'Cancel',
            acceptLabel: options.acceptLabel ?? 'Confirm',
            rejectClass: 'p-button-secondary p-button-outlined',
            acceptClass: options.acceptClass ?? '',
            accept: options.onAccept,
            reject: options.onReject,
        });
    }

    return {
        confirmDelete,
        confirmAction,
    };
}
