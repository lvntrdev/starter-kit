<script setup lang="ts">
    import { computed, onBeforeUnmount, ref, watch } from 'vue';
    import { Button, ButtonGroup } from 'primevue';
    import Tooltip from 'primevue/tooltip';

    const vTooltip = Tooltip;
    import { useEditor, EditorContent } from '@tiptap/vue-3';
    import { BubbleMenu } from '@tiptap/vue-3/menus';
    import StarterKit from '@tiptap/starter-kit';
    import Image from '@tiptap/extension-image';
    import Placeholder from '@tiptap/extension-placeholder';
    import Link from '@tiptap/extension-link';
    import TextAlign from '@tiptap/extension-text-align';
    import { Table } from '@tiptap/extension-table';
    import { TableRow } from '@tiptap/extension-table-row';
    import { TableHeader } from '@tiptap/extension-table-header';
    import { TableCell } from '@tiptap/extension-table-cell';
    import { Color } from '@tiptap/extension-color';
    import { TextStyle } from '@tiptap/extension-text-style';
    import type { Extensions } from '@tiptap/core';
    import InputText from 'primevue/inputtext';
    import Popover from 'primevue/popover';
    import EditorColorPalette from '@lvntr/components/FormBuilder/inputs/EditorColorPalette.vue';
    import type { EditorImageUploadConfig, EditorToolbarPreset } from '@lvntr/components/FormBuilder/core';
    import type { FileItem } from '@lvntr/components/FileManager/types';
    import EditorImagePicker from '@lvntr/components/FormBuilder/inputs/EditorImagePicker.vue';
    import { useDialog } from '@/composables/useDialog';
    import { useToast } from 'primevue/usetoast';
    import { trans } from 'laravel-vue-i18n';

    interface Props {
        id?: string;
        modelValue: string;
        placeholder?: string;
        toolbar?: EditorToolbarPreset;
        minHeight?: string;
        imageUpload?: EditorImageUploadConfig;
        links?: boolean;
        treatEmptyAsBlank?: boolean;
        disabled?: boolean;
        invalid?: boolean;
    }

    const props = withDefaults(defineProps<Props>(), {
        id: undefined,
        placeholder: undefined,
        toolbar: 'standard',
        minHeight: '10rem',
        imageUpload: undefined,
        links: false,
        treatEmptyAsBlank: true,
        disabled: false,
        invalid: false,
    });

    const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

    const defaultAcceptedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const acceptedMimes = computed(() => props.imageUpload?.acceptedMimes ?? defaultAcceptedMimes);

    type TableBorderStyle = 'none' | 'light' | 'normal' | 'bold' | 'primary';

    function tableClassFor(style: TableBorderStyle): string {
        return `sk-rte__table sk-rte__table--${style}`;
    }

    function parseTableBorderStyle(className: string | null | undefined): TableBorderStyle {
        const match = (className ?? '').match(/sk-rte__table--(none|light|normal|bold|primary)/);
        return (match?.[1] as TableBorderStyle) ?? 'light';
    }

    const CustomTable = Table.extend({
        addAttributes() {
            return {
                ...this.parent?.(),
                class: {
                    default: tableClassFor('light'),
                    parseHTML: (el) => el.getAttribute('class') ?? tableClassFor('light'),
                    renderHTML: (attrs) => ({ class: (attrs.class as string) ?? tableClassFor('light') }),
                },
                borderColor: {
                    default: null,
                    parseHTML: (el) => {
                        const styleValue = el.getAttribute('style') ?? '';
                        return styleValue.match(/--sk-table-border:\s*([^;]+)/i)?.[1]?.trim() ?? null;
                    },
                    renderHTML: (attrs) =>
                        attrs.borderColor ? { style: `--sk-table-border: ${attrs.borderColor as string}` } : {},
                },
            };
        },
    });

    const CustomImage = Image.extend({
        addAttributes() {
            return {
                ...this.parent?.(),
                width: {
                    default: null,
                    parseHTML: (el) => {
                        const styleWidth = (el.getAttribute('style') ?? '').match(/width:\s*([^;]+)/i)?.[1]?.trim();
                        return styleWidth ?? el.getAttribute('width');
                    },
                    renderHTML: (attrs) => (attrs.width ? { style: `width: ${attrs.width as string}` } : {}),
                },
                'data-align': {
                    default: null,
                    parseHTML: (el) => el.getAttribute('data-align'),
                    renderHTML: (attrs) => (attrs['data-align'] ? { 'data-align': attrs['data-align'] as string } : {}),
                },
            };
        },
    });

    const extensions: Extensions = [
        // Tiptap v3 bundles the Link extension into StarterKit; disable it here so the
        // optional manual-push branch below (with our own openOnClick/autolink config)
        // is the only source — otherwise Tiptap warns "Duplicate extension names found: ['link']".
        StarterKit.configure({ heading: { levels: [2, 3, 4] }, link: false }),
        Placeholder.configure({ placeholder: () => props.placeholder ?? '' }),
        TextAlign.configure({
            types: ['heading', 'paragraph'],
            alignments: ['left', 'center', 'right', 'justify'],
        }),
        CustomTable.configure({ resizable: true }),
        TableRow,
        TableHeader,
        TableCell,
        TextStyle,
        Color.configure({ types: ['textStyle'] }),
    ];
    if (props.links) {
        extensions.push(Link.configure({ openOnClick: false, autolink: true }));
    }
    if (props.imageUpload) {
        extensions.push(CustomImage.configure({ inline: true, allowBase64: false }));
    }

    const uploadingImage = ref(false);
    const dialog = useDialog();
    const toast = useToast();

    const editor = useEditor({
        extensions,
        content: props.modelValue,
        editable: !props.disabled,
        onUpdate: ({ editor }) => {
            const html = editor.getHTML();
            const out = props.treatEmptyAsBlank && editor.isEmpty ? '' : html;
            emit('update:modelValue', out);
        },
        editorProps: {
            attributes: {
                class: 'sk-rte__content',
                spellcheck: 'true',
            },
            handlePaste: (_view, event) => handleImageEvent(event as unknown as ClipboardEvent, 'paste'),
            handleDrop: (_view, event) => handleImageEvent(event as unknown as DragEvent, 'drop'),
        },
    });

    watch(
        () => props.modelValue,
        (next) => {
            const current = editor.value?.getHTML() ?? '';
            if (editor.value && next !== current) {
                editor.value.commands.setContent(next || '', { emitUpdate: false });
            }
        },
    );

    /**
     * Replicate onUpdate's emit logic after a manual setContent({ emitUpdate: false }).
     * Needed when we mutate editor content programmatically (image upload) but still
     * want the parent v-model to stay in sync — otherwise stale preview blob: URLs or
     * leftover error fragments end up in the submitted form payload.
     */
    function syncModelFromEditor(): void {
        if (!editor.value) return;
        const html = editor.value.getHTML();
        const out = props.treatEmptyAsBlank && editor.value.isEmpty ? '' : html;
        emit('update:modelValue', out);
    }

    watch(
        () => props.disabled,
        (d) => editor.value?.setEditable(!d),
    );

    onBeforeUnmount(() => {
        editor.value?.destroy();
    });

    function xsrfToken(): string {
        const match = document.cookie.match(/(^|;\s*)XSRF-TOKEN=([^;]*)/);
        return match ? decodeURIComponent(match[2]) : '';
    }

    function uploadFile(file: File): Promise<string> {
        return new Promise((resolve, reject) => {
            const cfg = props.imageUpload;
            if (!cfg) {
                reject(new Error('image upload not configured'));
                return;
            }
            const formData = new FormData();
            formData.append('context', cfg.context);
            if (cfg.contextId != null) formData.append('context_id', String(cfg.contextId));
            if (cfg.folderId) formData.append('folder_id', cfg.folderId);
            if (cfg.folderName) formData.append('folder_name', cfg.folderName);
            formData.append('files[]', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/file-manager/files');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.withCredentials = true;

            xhr.onload = () => {
                if (xhr.status === 413) {
                    reject(new Error('sk-file-manager.errors.too_large'));
                    return;
                }
                try {
                    const envelope = JSON.parse(xhr.responseText);
                    if (xhr.status >= 200 && xhr.status < 300) {
                        const url = envelope?.data?.files?.[0]?.url;
                        if (typeof url === 'string' && url.length > 0) {
                            resolve(url);
                        } else {
                            reject(new Error('invalid upload response'));
                        }
                    } else {
                        const firstError = Object.values(envelope?.errors ?? {})[0];
                        const msg = Array.isArray(firstError) ? firstError[0] : (firstError ?? envelope?.message);
                        reject(new Error(typeof msg === 'string' ? msg : `upload failed (${xhr.status})`));
                    }
                } catch {
                    reject(new Error(xhr.status === 0 ? 'network error' : `upload failed (${xhr.status})`));
                }
            };
            xhr.onerror = () => reject(new Error('network error'));
            xhr.send(formData);
        });
    }

    async function insertImageFromFile(file: File): Promise<void> {
        if (!props.imageUpload || !editor.value) return;
        if (!acceptedMimes.value.includes(file.type)) return;

        const previewUrl = URL.createObjectURL(file);
        editor.value
            .chain()
            .focus()
            .insertContent({
                type: 'image',
                attrs: { src: previewUrl, alt: file.name, width: defaultImageWidth },
            })
            .insertContent(' ')
            .run();
        uploadingImage.value = true;

        try {
            const finalUrl = await uploadFile(file);
            const html = (editor.value.getHTML() ?? '').split(previewUrl).join(finalUrl);
            editor.value.commands.setContent(html, { emitUpdate: false });
            syncModelFromEditor();
        } catch (err) {
            const cleaned = (editor.value.getHTML() ?? '').replace(
                new RegExp(`<img[^>]*src="${escapeRegex(previewUrl)}"[^>]*>`, 'g'),
                '',
            );
            editor.value.commands.setContent(cleaned, { emitUpdate: false });
            syncModelFromEditor();
            const raw = (err as Error).message ?? '';
            const detail =
                raw.startsWith('sk-') || raw.startsWith('validation.')
                    ? (() => {
                        const translated = trans(raw);
                        return translated === raw ? trans('sk-editor.image_upload_failed') : translated;
                    })()
                    : raw || trans('sk-editor.image_upload_failed');
            toast.add({
                severity: 'error',
                group: 'bc',
                summary: trans('sk-editor.image_upload_failed'),
                detail,
                life: 4000,
            });
        } finally {
            URL.revokeObjectURL(previewUrl);
            uploadingImage.value = false;
        }
    }

    function escapeRegex(input: string): string {
        return input.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function handleImageEvent(event: ClipboardEvent | DragEvent, kind: 'paste' | 'drop'): boolean {
        if (!props.imageUpload) return false;
        const files =
            kind === 'paste'
                ? Array.from((event as ClipboardEvent).clipboardData?.files ?? [])
                : Array.from((event as DragEvent).dataTransfer?.files ?? []);
        const image = files.find((f) => acceptedMimes.value.includes(f.type));
        if (!image) return false;
        event.preventDefault();
        void insertImageFromFile(image);
        return true;
    }

    function pickImage(): void {
        if (!props.imageUpload || !editor.value) return;
        dialog.open(
            EditorImagePicker,
            {
                context: props.imageUpload.context,
                contextId: props.imageUpload.contextId ?? null,
                folderId: props.imageUpload.folderId ?? null,
                acceptedMimes: acceptedMimes.value,
                onPick: (file: FileItem) => insertUploadedImage(file),
            },
            trans('sk-editor.picker_title'),
            { width: '720px' },
        );
    }

    const defaultImageWidth = '200px';

    function insertUploadedImage(file: FileItem): void {
        if (!editor.value) return;
        editor.value
            .chain()
            .focus()
            .insertContent({
                type: 'image',
                attrs: { src: file.url, alt: file.name, width: defaultImageWidth },
            })
            .insertContent(' ')
            .run();
    }

    function promptLink(): void {
        if (!editor.value) return;
        const current = editor.value.getAttributes('link').href as string | undefined;
        const url = window.prompt('URL', current ?? 'https://');
        if (url === null) return;
        if (url === '') {
            editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }

    function setAlign(align: 'left' | 'center' | 'right' | 'justify'): void {
        if (!editor.value) return;
        editor.value.chain().focus().setTextAlign(align).run();
    }

    function setImageWidth(width: string | null): void {
        if (!editor.value) return;
        editor.value.chain().focus().updateAttributes('image', { width }).run();
    }

    const customWidthInput = ref<string>('');

    function applyCustomWidth(): void {
        const raw = customWidthInput.value.trim();
        if (raw === '') return;

        const match = raw.match(/^(\d{1,4})(?:\s*(%|px))?$/);
        if (!match) return;

        const unit = match[2] ?? '%';
        const value = Number(match[1]);
        if (unit === '%' && (value <= 0 || value > 100)) return;

        setImageWidth(`${value}${unit}`);
        customWidthInput.value = '';
    }

    function setImageAlign(align: 'left' | 'center' | 'right' | null): void {
        if (!editor.value) return;
        editor.value.chain().focus().updateAttributes('image', { 'data-align': align }).run();
    }

    function deleteImage(): void {
        if (!editor.value) return;
        editor.value.chain().focus().deleteSelection().run();
    }

    function insertTable(): void {
        if (!editor.value) return;
        editor.value.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
    }

    function deleteTable(): void {
        if (!editor.value) return;
        editor.value.chain().focus().deleteTable().run();
    }

    function addColumnAfter(): void {
        editor.value?.chain().focus().addColumnAfter().run();
    }

    function addRowAfter(): void {
        editor.value?.chain().focus().addRowAfter().run();
    }

    function deleteColumn(): void {
        editor.value?.chain().focus().deleteColumn().run();
    }

    function deleteRow(): void {
        editor.value?.chain().focus().deleteRow().run();
    }

    function setTableBorderStyle(style: TableBorderStyle): void {
        editor.value
            ?.chain()
            .focus()
            .updateAttributes('table', { class: tableClassFor(style) })
            .run();
    }

    function toggleHeaderRow(): void {
        editor.value?.chain().focus().toggleHeaderRow().run();
    }

    const currentTableBorderStyle = computed<TableBorderStyle>(() => {
        if (!editor.value) return 'light';
        return parseTableBorderStyle(editor.value.getAttributes('table').class as string | undefined);
    });

    const colorPopover = ref<InstanceType<typeof Popover> | null>(null);

    function toggleColorPopover(event: Event): void {
        colorPopover.value?.toggle(event);
    }

    function pickColor(hex: string): void {
        editor.value?.chain().focus().setColor(hex).run();
        colorPopover.value?.hide();
    }

    function clearColor(): void {
        editor.value?.chain().focus().unsetColor().run();
        colorPopover.value?.hide();
    }

    const currentColor = computed<string | null>(() => {
        if (!editor.value) return null;
        const color = editor.value.getAttributes('textStyle').color as string | undefined;
        return color ?? null;
    });

    const tableBorderPopover = ref<InstanceType<typeof Popover> | null>(null);

    function toggleTableBorderPopover(event: Event): void {
        tableBorderPopover.value?.toggle(event);
    }

    function pickTableBorderColor(hex: string): void {
        editor.value?.chain().focus().updateAttributes('table', { borderColor: hex }).run();
        tableBorderPopover.value?.hide();
    }

    function clearTableBorderColor(): void {
        editor.value?.chain().focus().updateAttributes('table', { borderColor: null }).run();
        tableBorderPopover.value?.hide();
    }

    const currentTableBorderColor = computed<string | null>(() => {
        if (!editor.value) return null;
        return (editor.value.getAttributes('table').borderColor as string | null) ?? null;
    });

    const showLists = computed(() => props.toolbar === 'standard' || props.toolbar === 'full');
    const showFull = computed(() => props.toolbar === 'full');

    const currentImageWidth = computed(() => {
        if (!editor.value) return null;
        return (editor.value.getAttributes('image').width as string | null) ?? null;
    });
    const currentImageAlign = computed(() => {
        if (!editor.value) return null;
        return (editor.value.getAttributes('image')['data-align'] as string | null) ?? null;
    });
</script>

<template>
    <div class="sk-rte" :class="{ 'sk-rte--invalid': invalid, 'sk-rte--disabled': disabled }">
        <div v-if="editor" class="sk-rte__toolbar">
            <ButtonGroup>
                <Button
                    v-tooltip.top="$t('sk-editor.bold')"
                    type="button"
                    label="B"
                    size="small"
                    text
                    :pt="{ label: { class: 'font-bold' } }"
                    :aria-label="$t('sk-editor.bold')"
                    :severity="editor.isActive('bold') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleBold().run()"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.italic')"
                    type="button"
                    label="I"
                    size="small"
                    text
                    :pt="{ label: { class: 'italic font-serif' } }"
                    :aria-label="$t('sk-editor.italic')"
                    :severity="editor.isActive('italic') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleItalic().run()"
                />
                <Button
                    v-if="toolbar !== 'minimal'"
                    v-tooltip.top="$t('sk-editor.strike')"
                    type="button"
                    label="S"
                    size="small"
                    text
                    :pt="{ label: { class: 'line-through' } }"
                    :aria-label="$t('sk-editor.strike')"
                    :severity="editor.isActive('strike') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleStrike().run()"
                />
            </ButtonGroup>

            <ButtonGroup v-if="showFull">
                <Button
                    v-tooltip.top="$t('sk-editor.h2')"
                    type="button"
                    label="H2"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.h2')"
                    :severity="editor.isActive('heading', { level: 2 }) ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.h3')"
                    type="button"
                    label="H3"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.h3')"
                    :severity="editor.isActive('heading', { level: 3 }) ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
                />
            </ButtonGroup>

            <ButtonGroup v-if="showLists">
                <Button
                    v-tooltip.top="$t('sk-editor.bullet_list')"
                    type="button"
                    icon="pi pi-list"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.bullet_list')"
                    :severity="editor.isActive('bulletList') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleBulletList().run()"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.ordered_list')"
                    type="button"
                    icon="pi pi-sort-numeric-down"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.ordered_list')"
                    :severity="editor.isActive('orderedList') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleOrderedList().run()"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.blockquote')"
                    type="button"
                    icon="pi pi-align-justify"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.blockquote')"
                    :severity="editor.isActive('blockquote') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleBlockquote().run()"
                />
            </ButtonGroup>

            <ButtonGroup v-if="showLists">
                <Button
                    v-tooltip.top="$t('sk-editor.align_left')"
                    type="button"
                    icon="pi pi-align-left"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.align_left')"
                    :severity="editor.isActive({ textAlign: 'left' }) ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="setAlign('left')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.align_center')"
                    type="button"
                    icon="pi pi-align-center"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.align_center')"
                    :severity="editor.isActive({ textAlign: 'center' }) ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="setAlign('center')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.align_right')"
                    type="button"
                    icon="pi pi-align-right"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.align_right')"
                    :severity="editor.isActive({ textAlign: 'right' }) ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="setAlign('right')"
                />
            </ButtonGroup>

            <ButtonGroup>
                <Button
                    v-tooltip.top="$t('sk-editor.color')"
                    type="button"
                    icon="pi pi-palette"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.color')"
                    :pt="{ icon: { style: currentColor ? { color: currentColor } : {} } }"
                    :severity="currentColor ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="toggleColorPopover"
                />
            </ButtonGroup>

            <ButtonGroup v-if="links || imageUpload">
                <Button
                    v-if="links"
                    v-tooltip.top="$t('sk-editor.link')"
                    type="button"
                    icon="pi pi-link"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.link')"
                    :severity="editor.isActive('link') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="promptLink"
                />
                <Button
                    v-if="imageUpload"
                    v-tooltip.top="$t('sk-editor.image')"
                    type="button"
                    icon="pi pi-image"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.image')"
                    :loading="uploadingImage"
                    :disabled="disabled || uploadingImage"
                    @click="pickImage"
                />
            </ButtonGroup>

            <ButtonGroup v-if="showLists">
                <Button
                    v-tooltip.top="$t('sk-editor.table_insert')"
                    type="button"
                    icon="pi pi-table"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.table_insert')"
                    :severity="editor.isActive('table') ? 'primary' : 'secondary'"
                    :disabled="disabled || editor.isActive('table')"
                    @click="insertTable"
                />
            </ButtonGroup>

            <ButtonGroup v-if="showFull">
                <Button
                    v-tooltip.top="$t('sk-editor.code_block')"
                    type="button"
                    icon="pi pi-code"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.code_block')"
                    :severity="editor.isActive('codeBlock') ? 'primary' : 'secondary'"
                    :disabled="disabled"
                    @click="editor.chain().focus().toggleCodeBlock().run()"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.horizontal_rule')"
                    type="button"
                    icon="pi pi-minus"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.horizontal_rule')"
                    :disabled="disabled"
                    @click="editor.chain().focus().setHorizontalRule().run()"
                />
            </ButtonGroup>

            <ButtonGroup v-if="showFull">
                <Button
                    v-tooltip.top="$t('sk-editor.undo')"
                    type="button"
                    icon="pi pi-undo"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.undo')"
                    :disabled="disabled || !editor.can().undo()"
                    @click="editor.chain().focus().undo().run()"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.redo')"
                    type="button"
                    icon="pi pi-refresh"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.redo')"
                    :disabled="disabled || !editor.can().redo()"
                    @click="editor.chain().focus().redo().run()"
                />
            </ButtonGroup>
        </div>

        <BubbleMenu
            v-if="editor && imageUpload"
            :editor="editor"
            :should-show="({ editor }) => editor.isActive('image')"
            plugin-key="sk-editor-image-bubble"
        >
            <div class="sk-rte__bubble">
                <Button
                    v-tooltip.top="$t('sk-editor.image_size_s')"
                    type="button"
                    label="S"
                    size="small"
                    text
                    :severity="currentImageWidth === '200px' ? 'primary' : 'secondary'"
                    @click="setImageWidth('200px')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.image_size_m')"
                    type="button"
                    label="M"
                    size="small"
                    text
                    :severity="currentImageWidth === '400px' ? 'primary' : 'secondary'"
                    @click="setImageWidth('400px')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.image_size_l')"
                    type="button"
                    label="L"
                    size="small"
                    text
                    :severity="currentImageWidth === '600px' ? 'primary' : 'secondary'"
                    @click="setImageWidth('600px')"
                />
                <Button
                    type="button"
                    label="100%"
                    size="small"
                    text
                    :severity="currentImageWidth === '100%' ? 'primary' : 'secondary'"
                    @click="setImageWidth('100%')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.image_size_auto')"
                    type="button"
                    icon="pi pi-expand"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.image_size_auto')"
                    :severity="currentImageWidth === null ? 'primary' : 'secondary'"
                    @click="setImageWidth(null)"
                />
                <span class="sk-rte__bubble-sep" />
                <InputText
                    v-model="customWidthInput"
                    v-tooltip.top="$t('sk-editor.image_width_placeholder')"
                    size="small"
                    class="sk-rte__bubble-input"
                    :placeholder="currentImageWidth ?? $t('sk-editor.image_width_placeholder')"
                    :aria-label="$t('sk-editor.image_width_placeholder')"
                    @keydown.enter.prevent="applyCustomWidth"
                    @blur="applyCustomWidth"
                />
                <span class="sk-rte__bubble-sep" />
                <Button
                    v-tooltip.top="$t('sk-editor.align_left')"
                    type="button"
                    icon="pi pi-align-left"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.align_left')"
                    :severity="currentImageAlign === 'left' ? 'primary' : 'secondary'"
                    @click="setImageAlign('left')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.align_center')"
                    type="button"
                    icon="pi pi-align-center"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.align_center')"
                    :severity="currentImageAlign === 'center' ? 'primary' : 'secondary'"
                    @click="setImageAlign('center')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.align_right')"
                    type="button"
                    icon="pi pi-align-right"
                    size="small"
                    text
                    :aria-label="$t('sk-editor.align_right')"
                    :severity="currentImageAlign === 'right' ? 'primary' : 'secondary'"
                    @click="setImageAlign('right')"
                />
                <span class="sk-rte__bubble-sep" />
                <Button
                    v-tooltip.top="$t('sk-editor.delete_image')"
                    type="button"
                    icon="pi pi-trash"
                    size="small"
                    text
                    severity="danger"
                    :aria-label="$t('sk-editor.delete_image')"
                    @click="deleteImage"
                />
            </div>
        </BubbleMenu>

        <BubbleMenu
            v-if="editor"
            :editor="editor"
            :should-show="({ editor }) => editor.isActive('table') && !editor.isActive('image')"
            plugin-key="sk-editor-table-bubble"
        >
            <div class="sk-rte__bubble">
                <Button
                    v-tooltip.top="$t('sk-editor.table_add_column')"
                    type="button"
                    size="small"
                    text
                    :label="`+ ${$t('sk-editor.table_col')}`"
                    :disabled="disabled"
                    @click="addColumnAfter"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.table_add_row')"
                    type="button"
                    size="small"
                    text
                    :label="`+ ${$t('sk-editor.table_row')}`"
                    :disabled="disabled"
                    @click="addRowAfter"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.table_delete_column')"
                    type="button"
                    size="small"
                    text
                    severity="warn"
                    :label="`− ${$t('sk-editor.table_col')}`"
                    :disabled="disabled"
                    @click="deleteColumn"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.table_delete_row')"
                    type="button"
                    size="small"
                    text
                    severity="warn"
                    :label="`− ${$t('sk-editor.table_row')}`"
                    :disabled="disabled"
                    @click="deleteRow"
                />
                <span class="sk-rte__bubble-sep" />
                <Button
                    v-tooltip.top="$t('sk-editor.table_toggle_header')"
                    type="button"
                    icon="pi pi-th-large"
                    size="small"
                    text
                    :disabled="disabled"
                    @click="toggleHeaderRow"
                />
                <span class="sk-rte__bubble-sep" />
                <Button
                    v-tooltip.top="$t('sk-editor.table_border_none')"
                    type="button"
                    label="—"
                    size="small"
                    text
                    :severity="currentTableBorderStyle === 'none' ? 'primary' : 'secondary'"
                    @click="setTableBorderStyle('none')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.table_border_light')"
                    type="button"
                    label="░"
                    size="small"
                    text
                    :pt="{ label: { class: 'text-surface-400' } }"
                    :severity="currentTableBorderStyle === 'light' ? 'primary' : 'secondary'"
                    @click="setTableBorderStyle('light')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.table_border_normal')"
                    type="button"
                    label="▦"
                    size="small"
                    text
                    :severity="currentTableBorderStyle === 'normal' ? 'primary' : 'secondary'"
                    @click="setTableBorderStyle('normal')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.table_border_bold')"
                    type="button"
                    label="▣"
                    size="small"
                    text
                    :pt="{ label: { class: 'font-bold' } }"
                    :severity="currentTableBorderStyle === 'bold' ? 'primary' : 'secondary'"
                    @click="setTableBorderStyle('bold')"
                />
                <Button
                    v-tooltip.top="$t('sk-editor.table_border_custom')"
                    type="button"
                    icon="pi pi-palette"
                    size="small"
                    text
                    :pt="{ icon: { style: currentTableBorderColor ? { color: currentTableBorderColor } : {} } }"
                    :severity="currentTableBorderColor ? 'primary' : 'secondary'"
                    @click="toggleTableBorderPopover"
                />
                <span class="sk-rte__bubble-sep" />
                <Button
                    v-tooltip.top="$t('sk-editor.table_delete')"
                    type="button"
                    icon="pi pi-trash"
                    size="small"
                    text
                    severity="danger"
                    :disabled="disabled"
                    @click="deleteTable"
                />
            </div>
        </BubbleMenu>

        <Popover ref="colorPopover">
            <EditorColorPalette :current="currentColor" @pick="pickColor" @clear="clearColor" />
        </Popover>

        <Popover ref="tableBorderPopover">
            <EditorColorPalette
                :current="currentTableBorderColor"
                @pick="pickTableBorderColor"
                @clear="clearTableBorderColor"
            />
        </Popover>

        <EditorContent :id="id" :editor="editor" class="sk-rte__body" :style="{ minHeight }" />
    </div>
</template>
