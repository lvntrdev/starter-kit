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
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}
