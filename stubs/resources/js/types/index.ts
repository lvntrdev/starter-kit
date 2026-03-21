// resources/js/types/index.ts

import type { User } from './user';

export type { User, UserStatus } from './user';

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export interface FlashMessages {
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
    status?: string;
}

export interface SharedPageProps {
    auth: {
        user: User | null;
        role: string | null;
        role_names: string[];
        permissions: string[];
    };
    flash: FlashMessages;
    enums: Record<string, Array<{ value: string | number; label: string; severity: string }>>;
}

export interface MenuItem {
    title: string;
    icon?: string;
    href?: string;
    external?: boolean;
    section?: boolean;
    children?: MenuItem[];
    permission?: string;
    role?: string | string[];
}

export interface MenuContext {
    isItemActive: (item: MenuItem) => boolean;
    isGroupOpen: (item: MenuItem) => boolean;
}
