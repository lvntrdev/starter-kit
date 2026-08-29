import type { FieldConfig, PasswordFieldConfig } from './types';

/**
 * Field types whose PrimeVue component renders a non-focusable wrapper element as
 * its root and keeps the real control inside.
 *
 * For these the field key stays on the wrapper (public markup unchanged), while the
 * inner control receives `${key}__control` through PrimeVue's `inputId` prop — so a
 * `<label for>` finally targets something focusable instead of the wrapper.
 *
 * `password` is listed here but resolved conditionally (see {@link passwordUsesWrapper}):
 * without `feedback` SkFormInput renders a plain `<InputText>` that already carries
 * the field key on the input itself.
 */
const WRAPPER_CONTROL_TYPES = new Set<string>([
    'input-number',
    'date-picker',
    'select',
    'multiselect',
    'password',
    'toggle-switch',
]);

/**
 * True when a password field renders through PrimeVue's `<Password>` wrapper.
 *
 * Mirrors SkFormInput's `useCustomPasswordInput` (which imports this helper), so the
 * id contract and the render branch can never drift apart.
 */
export function passwordUsesWrapper(field: FieldConfig): boolean {
    return field.type === 'password' && !!(field as PasswordFieldConfig).feedback;
}

/**
 * Id of the focusable control for a field — the `<label for>` target and the value
 * passed to PrimeVue's `inputId`.
 *
 * Returns the plain field key for every single-element control (InputText, InputMask,
 * Textarea, Checkbox, …) so existing `label[for=key]` bindings keep working.
 */
export function controlId(field: FieldConfig): string {
    if (field.type === 'password') {
        return passwordUsesWrapper(field) ? `${field.key}__control` : field.key;
    }

    return WRAPPER_CONTROL_TYPES.has(field.type) ? `${field.key}__control` : field.key;
}

/**
 * Id of the single error/hint `<small>` SkFormFieldRenderer prints for a field —
 * the `aria-describedby` target. Only one of error / option-error / hint renders at
 * a time, so a single id is enough.
 */
export function describedById(field: FieldConfig): string {
    return `${field.key}__desc`;
}
