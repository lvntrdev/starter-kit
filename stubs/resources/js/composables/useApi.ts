// resources/js/composables/useApi.ts

/**
 * Lightweight composable for making API requests to the Laravel backend.
 *
 * Automatically sets Accept: application/json and X-Requested-With headers,
 * reads XSRF-TOKEN from cookies for CSRF protection, and unwraps the
 * ApiResponse JSON envelope.
 *
 * Usage:
 *   const api = useApi();
 *   const user = await api.get<User>('/admin/users/1/data');
 *   await api.post('/admin/users', { name: 'Ali', email: 'ali@test.com' });
 *   await api.put('/admin/users/1', { name: 'Veli' });
 *   await api.delete('/admin/users/1');
 */

import { useToast } from 'primevue/usetoast';

/** Standard API response envelope returned by ApiResponse / to_api() */
export interface ApiEnvelope<T = unknown> {
    success: boolean;
    status: number;
    message: string;
    data: T;
    errors?: Record<string, string[]>;
    meta?: Record<string, unknown>;
}

/** Error class carrying the full API envelope for downstream handling */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly body: ApiEnvelope,
    ) {
        super(body.message || `API error: ${status}`);
        this.name = 'ApiError';
    }
}

/**
 * Read a cookie value by name (used for XSRF-TOKEN).
 */
function getCookie(name: string): string | undefined {
    if (typeof document === 'undefined') return undefined;
    const match = document.cookie.match(new RegExp(`(^|;\\s*)${name}=([^;]*)`));
    return match ? decodeURIComponent(match[2]) : undefined;
}

type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

async function request<T = unknown>(method: HttpMethod, url: string, payload?: unknown): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    // CSRF token for mutating requests
    const xsrf = getCookie('XSRF-TOKEN');
    if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    const options: RequestInit = { method, headers, credentials: 'same-origin' };

    if (payload !== undefined && method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(payload);
    }

    const response = await fetch(url, options);

    // 204 No Content — return null
    if (response.status === 204) {
        return null as T;
    }

    let json: ApiEnvelope<T>;
    try {
        json = (await response.json()) as ApiEnvelope<T>;
    } catch {
        // Non-JSON response (HTML error page, empty body, proxy timeout, etc.)
        // Synthesize an envelope so the caller gets a consistent ApiError.
        throw new ApiError(response.status, {
            success: false,
            status: response.status,
            message: response.ok ? 'Sunucudan geçersiz yanıt alındı.' : `İstek başarısız oldu (${response.status}).`,
            data: null as unknown as T,
        });
    }

    if (!response.ok || !json.success) {
        throw new ApiError(response.status, json as ApiEnvelope);
    }

    return json.data;
}

export interface UseApiOptions {
    /** Show toast on error (default: true). Pass false to handle errors manually. */
    toast?: boolean;
}

export function useApi(apiOptions: UseApiOptions = {}) {
    const showToast = apiOptions.toast !== false;
    let toast: ReturnType<typeof useToast> | null = null;

    if (showToast) {
        try {
            toast = useToast();
        } catch {
            // Outside component context — toast unavailable
        }
    }

    async function withErrorHandling<T>(promise: Promise<T>): Promise<T> {
        try {
            return await promise;
        } catch (error) {
            if (toast) {
                const detail =
                    error instanceof ApiError
                        ? error.message
                        : error instanceof Error && error.message
                          ? error.message
                          : 'Ağ hatası. Lütfen tekrar deneyin.';

                toast.add({
                    severity: 'error',
                    summary: 'Hata',
                    detail,
                    group: 'bc',
                    life: 5000,
                });
            }
            throw error;
        }
    }

    return {
        /** GET request — returns unwrapped data */
        get: <T = unknown>(url: string) => withErrorHandling(request<T>('GET', url)),

        /** POST request — returns unwrapped data */
        post: <T = unknown>(url: string, payload?: unknown) => withErrorHandling(request<T>('POST', url, payload)),

        /** PUT request — returns unwrapped data */
        put: <T = unknown>(url: string, payload?: unknown) => withErrorHandling(request<T>('PUT', url, payload)),

        /** PATCH request — returns unwrapped data */
        patch: <T = unknown>(url: string, payload?: unknown) => withErrorHandling(request<T>('PATCH', url, payload)),

        /** DELETE request — returns unwrapped data */
        delete: <T = unknown>(url: string, payload?: unknown) => withErrorHandling(request<T>('DELETE', url, payload)),
    };
}
