import axios, { AxiosError } from 'axios';

/**
 * Axios instance for the Laravel API. Sanctum SPA auth: the session cookie
 * travels automatically and the XSRF-TOKEN cookie is echoed as a header.
 */
export const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

export const csrf = () => axios.get('/sanctum/csrf-cookie', { withCredentials: true });

api.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
        const config = error.config as (typeof error.config & { _retried?: boolean }) | undefined;

        // CSRF token expired (419): refresh the cookie once and retry.
        if (error.response?.status === 419 && config && !config._retried) {
            config._retried = true;
            await csrf();
            return api(config);
        }

        if (error.response?.status === 401) {
            window.dispatchEvent(new CustomEvent('auth:unauthorized'));
        }

        return Promise.reject(error);
    },
);

type ErrorBody = { message?: string; errors?: Record<string, string[]> };

export function errorMessage(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
    if (axios.isAxiosError<ErrorBody>(error)) {
        if (error.response?.status === 429) return 'Too many requests. Please wait a moment and try again.';
        if (error.response?.status === 403) return error.response.data?.message || 'You do not have permission to do that.';
        return error.response?.data?.message || fallback;
    }
    return fallback;
}

export function fieldErrors(error: unknown): Record<string, string> {
    if (axios.isAxiosError<ErrorBody>(error) && error.response?.status === 422 && error.response.data?.errors) {
        return Object.fromEntries(Object.entries(error.response.data.errors).map(([k, v]) => [k, v[0]]));
    }
    return {};
}

/** Drop empty values so query strings stay clean. */
export function cleanParams<T extends Record<string, unknown>>(params: T): Partial<T> {
    return Object.fromEntries(
        Object.entries(params).filter(([, v]) => v !== '' && v !== null && v !== undefined && !(Array.isArray(v) && v.length === 0)),
    ) as Partial<T>;
}

/** Download an authenticated API file (e.g. an Excel report), named by the server's Content-Disposition. */
export async function downloadFile(url: string, params: Record<string, unknown>, fallbackName: string) {
    const res = await api.get<Blob>(url, { params: cleanParams(params), responseType: 'blob' });
    const disposition = String(res.headers['content-disposition'] ?? '');
    const name = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)?.[1] ?? fallbackName;
    const href = URL.createObjectURL(res.data);
    const a = document.createElement('a');
    a.href = href;
    a.download = decodeURIComponent(name);
    a.click();
    setTimeout(() => URL.revokeObjectURL(href), 60_000);
}

/** Fetch an authenticated file and open or save it (keeps the session cookie flow). */
export async function openFile(url: string, download?: string) {
    const res = await api.get(url, { responseType: 'blob', baseURL: '' });
    const href = URL.createObjectURL(res.data);
    if (download) {
        const a = document.createElement('a');
        a.href = href;
        a.download = download;
        a.click();
    } else {
        window.open(href, '_blank', 'noopener');
    }
    setTimeout(() => URL.revokeObjectURL(href), 60_000);
}
