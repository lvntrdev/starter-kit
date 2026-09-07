<script setup lang="ts">
    import { computed, useAttrs, useSlots } from 'vue';
    import { usePageHeaderHost } from './usePageHeaderHost';

    defineOptions({ name: 'SkCard', inheritAttrs: false });

    interface Props {
        /** Shorthand for the title slot — rendered as plain text. Pass a translated string. */
        title?: string;
        /** Shorthand for the subtitle slot — rendered as plain text. Pass a translated string. */
        subtitle?: string;
        /**
         * Render the card without surface — bg, border, radius and padding are
         * all dropped. Useful as an invisible wrapper inside dialogs or other
         * surfaces that already provide their own chrome.
         */
        transparent?: boolean;
        /**
         * Show a bottom divider line below the head (title + subtitle / actions)
         * so it reads as a distinct header above the body. Defaults to `true`.
         */
        divider?: boolean;
        /**
         * Remove the body padding so content sits flush against the card edges.
         * Useful for tables, toolbars or vertical-tab navigation that manage
         * their own internal spacing.
         */
        flush?: boolean;
        /**
         * Allow this card to host the page header (title / subtitle / back button /
         * page actions) in themes that put it inside the content card. The first
         * eligible card on the page wins. Set false for a card that must never
         * absorb it — a dialog body, a nested inner card.
         */
        hostPageHeader?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        title: undefined,
        subtitle: undefined,
        transparent: false,
        divider: true,
        flush: false,
        hostPageHeader: true,
    });

    const slots = useSlots();
    const attrs = useAttrs();

    // ── Page-header hosting ───────────────────────────────────
    // The layout provides the host context only in themes that want the page
    // header inside the content card; everywhere else the composable finds no
    // context and keeps this card exactly as it was.
    const { hostsPageHeader, hostedPageHeader } = usePageHeaderHost(
        () => props.hostPageHeader && !props.transparent,
        () => hasTitle.value,
    );

    // ── Head visibility ─────────────────────────────────────────────────
    const hasTitle = computed(() => !!props.title || !!slots.title);
    const hasSubtitle = computed(() => !!props.subtitle || !!slots.subtitle);
    // `title-end` is a back-compat alias for `actions` — both render to the
    // right-hand region of the head.
    const hasActions = computed(() => !!slots.actions || !!slots['title-end']);
    const hasHeader = computed(() => !!slots.header);
    const hasHead = computed(
        () => hasHeader.value || hasTitle.value || hasSubtitle.value || hasActions.value,
    );

    // A hosting card that has a title of its own keeps it and takes the page header
    // INTO that same head row (back button + page actions only) — a page heading
    // stacked above the card's own heading reads as a duplicated title.
    //
    // Never inline under a full `#header` override, though: that branch replaces the
    // structured head entirely, so the inline region is never rendered and the page's
    // back button and `#page-actions` would vanish from the screen with no fallback
    // (the layout stays silent while a card is still claiming the header). Such a card
    // draws the header in its own row above the override instead.
    const hostsPageHeaderInline = computed(
        () => hostsPageHeader.value && hasTitle.value && !hasHeader.value,
    );

    // Head becomes a flex row only when there is a right-hand region to lay out.
    const isRowHead = computed(() => hasActions.value || hostsPageHeaderInline.value);

    // ── Foot visibility ─────────────────────────────────────────────────
    const hasFoot = computed(() => !!slots.footer);

    // ── Root class ──────────────────────────────────────────────────────
    const rootClass = computed(() => {
        const classes: unknown[] = ['sk-card'];
        if (props.transparent) classes.push('sk-card--transparent');
        if (props.flush) classes.push('sk-card--flush');
        if (attrs.class) classes.push(attrs.class);
        return classes;
    });

    // The hosted page header gets a head row of its OWN, above the card's title
    // row — sharing that row would let a full-width header push the card's title
    // and actions out of the card.
    const pageHeaderClass = computed(() => {
        const classes: unknown[] = ['sk-card__head', 'sk-card__head--page'];
        if (props.divider) classes.push('sk-card__head--divider');
        return classes;
    });

    const headClass = computed(() => {
        const classes: unknown[] = ['sk-card__head'];
        if (isRowHead.value) classes.push('sk-card__head--row');
        // The divider only makes sense when there is a head to separate from the body.
        if (props.divider) classes.push('sk-card__head--divider');
        return classes;
    });
</script>

<template>
    <div :class="rootClass">
        <!-- ── Hosted page header ────────────────────────────────────── -->
        <!-- The layout's own page header, drawn here in themes that host it -->
        <div
            v-if="hostsPageHeader && !hostsPageHeaderInline"
            :class="pageHeaderClass"
            data-sk-page-header
        >
            <component :is="hostedPageHeader" />
        </div>

        <!-- ── Head ──────────────────────────────────────────────────── -->
        <div v-if="hasHead" :class="headClass">
            <!-- Full custom head override -->
            <slot v-if="hasHeader" name="header" />

            <!-- Structured head: title / subtitle group (+ optional actions) -->
            <template v-else>
                <div
                    v-if="hasTitle || hasSubtitle"
                    class="sk-card__head-main"
                >
                    <div v-if="hasTitle" class="sk-card__title">
                        <slot name="title">{{ title }}</slot>
                    </div>
                    <div v-if="hasSubtitle" class="sk-card__subtitle">
                        <slot name="subtitle">{{ subtitle }}</slot>
                    </div>
                </div>
                <div
                    v-if="hasActions || hostsPageHeaderInline"
                    class="sk-card__actions"
                    :data-sk-page-header="hostsPageHeaderInline ? '' : undefined"
                >
                    <component
                        :is="hostedPageHeader"
                        v-if="hostsPageHeaderInline"
                    />
                    <slot name="actions">
                        <slot name="title-end" />
                    </slot>
                </div>
            </template>
        </div>

        <!-- ── Body ──────────────────────────────────────────────────── -->
        <div class="sk-card__body">
            <slot name="content">
                <slot />
            </slot>
        </div>

        <!-- ── Foot ──────────────────────────────────────────────────── -->
        <div v-if="hasFoot" class="sk-card__foot">
            <slot name="footer" />
        </div>
    </div>
</template>
