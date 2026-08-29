// resources/js/components/Lvntr-Starter-Kit/FormBuilder/core/locales.ts

/**
 * Locale resolution shared by every translatable-field consumer.
 *
 * Two call sites have to agree or a create form silently ships the wrong shape:
 * `SkForm.buildTranslatableDefault()` seeds the empty `{ locale: '' }` record
 * that becomes the submitted payload, while `TranslatableInput.resolvedLocales`
 * decides which locale tabs actually render. When they disagree the form posts
 * keys the user was never shown (or drops keys the user filled in), so both go
 * through the helpers below.
 */

/** A resolved content locale: its code and its human-readable name. */
export interface ResolvedLocale {
    code: string;
    name: string;
}

/**
 * The subset of Inertia shared page props locale resolution reads.
 * Declared structurally so this module stays free of an app-level type import.
 */
export interface LocaleAwarePageProps {
    /** Admin UI translation locales — the fallback source. */
    availableLocales?: Record<string, string> | null;
    /** DB-backed content languages — the preferred source for translatable fields. */
    availableContentLocales?: Record<string, string> | null;
}

/** Field-level locale narrowing, as configured by `.onlyLocales()` / `.exceptLocales()`. */
export interface LocaleFilterable {
    onlyLocales?: string[];
    exceptLocales?: string[];
}

/**
 * Resolves the content locales for translatable fields.
 *
 * Content fields walk the *content* languages (DB-backed) — distinct from the
 * admin UI translation locales. Falls back to `availableLocales` when the
 * content-languages prop is absent or empty (old consumers / empty table /
 * installer pages), so multilingual forms never render an empty locale list.
 */
export function resolveContentLocales(props: LocaleAwarePageProps | null | undefined): ResolvedLocale[] {
    const contentLocales = props?.availableContentLocales;
    const available =
        contentLocales && Object.keys(contentLocales).length > 0 ? contentLocales : (props?.availableLocales ?? {});

    return Object.entries(available).map(([code, name]) => ({ code, name }));
}

/** Applies a field's `onlyLocales` / `exceptLocales` narrowing, in that order. */
export function applyLocaleFilters<T extends ResolvedLocale>(locales: T[], field: LocaleFilterable): T[] {
    let out = locales;
    if (field.onlyLocales?.length) {
        out = out.filter((l) => field.onlyLocales!.includes(l.code));
    }
    if (field.exceptLocales?.length) {
        out = out.filter((l) => !field.exceptLocales!.includes(l.code));
    }
    return out;
}
