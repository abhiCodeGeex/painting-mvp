/** The page object Laravel's Inertia middleware sends (same protocol as @inertiajs/react). */
export interface Page<P = Record<string, unknown>> {
    component: string;
    props: P & { errors: Record<string, string> };
    url: string;
    version: string | null;
    clearHistory?: boolean;
    encryptHistory?: boolean;
}

export type Method = 'get' | 'post' | 'put' | 'patch' | 'delete';

export type VisitData = Record<string, unknown> | FormData;

export interface VisitOptions {
    method?: Method;
    data?: VisitData;
    replace?: boolean;
    preserveScroll?: boolean | ((page: Page) => boolean);
    preserveState?: boolean | ((page: Page) => boolean);
    only?: string[];
    headers?: Record<string, string>;
    forceFormData?: boolean;
    onStart?: () => void;
    onSuccess?: (page: Page) => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
}
