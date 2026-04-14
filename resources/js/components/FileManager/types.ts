export type FileManagerContext = 'user' | 'global';

export type SortKey = 'name' | 'size' | 'date';
export type SortDirection = 'asc' | 'desc';

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
    created_at: string | null;
}

export interface FolderStats {
    file_count: number;
    total_size: number;
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
