/**
 * Context key that matches a backend `ContextRegistry` entry.
 *
 * Built-in: `'user'` and `'global'`. Any other key that the backend can
 * auto-resolve — morph-map alias or `App\Models\{Studly}` convention — or any
 * explicit `ContextRegistry::register()` entry works too (e.g. `'vehicle'`,
 * `'school'`). Contexts whose backend path contains `{id}` require `contextId`.
 */
export type FileManagerContext = 'user' | 'global' | (string & {});

export type SortKey = 'name' | 'size' | 'date';
export type SortDirection = 'asc' | 'desc';

export type ViewMode = 'grid' | 'list';
export type QuickView = 'all' | 'recent' | 'favorites' | 'trash';

export interface FolderNode {
    id: string;
    parent_id: string | null;
    name: string;
    children: FolderNode[];
}

export interface FolderSummary {
    id: string;
    parent_id: string | null;
    name: string;
    file_count?: number;
    total_size?: number;
    is_favorited?: boolean;
    deleted_at?: string | null;
}

export interface FileItem {
    id: number;
    uuid: string | null;
    name: string;
    file_name: string;
    mime_type: string;
    size: number;
    folder_id: string | null;
    url: string;
    /**
     * Permanent public URL, set only when the backing disk is public. The
     * editor embeds this into persisted rich text; `url` is session-gated and
     * would break for visitors. Null on a private disk.
     */
    public_url?: string | null;
    created_at: string | null;
    is_favorited?: boolean;
    deleted_at?: string | null;
}

export interface FolderStats {
    file_count: number;
    total_size: number;
    /** Total bytes across ALL collections for this owner (quota indicator). */
    storage_used?: number;
    /** Total byte budget for the disk-wide quota (settings.file_manager.storage_quota_gb). */
    storage_quota?: number;
}

export interface FolderContents {
    folder: FolderSummary | null;
    folders: FolderSummary[];
    files: FileItem[];
    stats: FolderStats;
}

export interface FileManagerProps {
    context: FileManagerContext;
    contextId?: string | null;
    readonly?: boolean;
    enableTrash?: boolean;
    acceptedMimes?: string[];
    maxSizeKb?: number;
    height?: string;
}

export type SelectionKey = `folder:${string}` | `file:${string}`;

export interface SelectedItem {
    type: 'folder' | 'file';
    id: string;
}

export interface PendingUpload {
    tempId: string;
    name: string;
    size: number;
    mimeType: string;
    progress: number;
    error: string | null;
    folderId: string | null;
}
