// resources/js/types/user.ts

export type UserStatus = 'active' | 'inactive' | 'banned';

export interface User {
    id: string;
    first_name: string;
    last_name: string;
    full_name: string;
    email: string;
    status: UserStatus;
    role?: string | null;
    avatar_url: string | null;
    identity_document_media?: Record<string, unknown> | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}
