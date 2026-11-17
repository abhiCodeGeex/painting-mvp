import { computed, Injectable, signal, type Type } from '@angular/core';
import { Progress } from './progress';
import type { Method, Page, VisitData, VisitOptions } from './types';

type PageModule = { default: Type<unknown> };

const pages = import.meta.glob<PageModule>('../pages/**/*.ts');

/**
 * Inertia client for Angular. Speaks the same protocol as @inertiajs/react, so every
 * Laravel controller, redirect, validation error and flash message works unchanged.
 */
@Injectable({ providedIn: 'root' })
export class InertiaRouter {
    /** The current page (component name, props, url). */
    readonly page = signal<Page>({ component: '', props: { errors: {} }, url: '/', version: null });
    /** The Angular component class for the current page. */
    readonly component = signal<Type<unknown> | null>(null);
    /** Changes whenever the page must be re-created (a visit without preserveState). */
    readonly key = signal(0);
    readonly props = computed(() => this.page().props as Record<string, unknown> & { errors: Record<string, string> });
    readonly processing = signal(false);

    private progress = new Progress('#4B5563');
    private abort: AbortController | null = null;
    private visitId = 0;

    async init(initial: Page): Promise<void> {
        history.replaceState({ page: initial }, '', initial.url);
        window.addEventListener('popstate', (event) => {
            const page = (event.state as { page?: Page } | null)?.page;
            if (page) void this.setPage(page, { preserveState: false });
            else window.location.reload();
        });
        await this.setPage(initial, { preserveState: false });
        this.scrollToHash();
    }

    // ---- public API (mirrors router.* from @inertiajs/react) ----

    visit(url: string, options: VisitOptions = {}): void {
        void this.request(url, options);
    }

    get(url: string, data: Record<string, unknown> = {}, options: VisitOptions = {}): void {
        this.visit(url, { ...options, method: 'get', data });
    }

    post(url: string, data: VisitData = {}, options: VisitOptions = {}): void {
        this.visit(url, { preserveState: true, ...options, method: 'post', data });
    }

    put(url: string, data: VisitData = {}, options: VisitOptions = {}): void {
        this.visit(url, { preserveState: true, ...options, method: 'put', data });
    }

    patch(url: string, data: VisitData = {}, options: VisitOptions = {}): void {
        this.visit(url, { preserveState: true, ...options, method: 'patch', data });
    }

    delete(url: string, options: VisitOptions = {}): void {
        this.visit(url, { preserveState: true, ...options, method: 'delete' });
    }

    reload(options: VisitOptions = {}): void {
        this.visit(window.location.href, { preserveScroll: true, preserveState: true, ...options });
    }

    // ---- internals ----

    private async request(url: string, options: VisitOptions): Promise<void> {
        const method: Method = options.method ?? 'get';
        const id = ++this.visitId;
        this.abort?.abort();
        const controller = new AbortController();
        this.abort = controller;

        let target = new URL(url, window.location.href);
        let body: BodyInit | undefined;
        const headers: Record<string, string> = {
            Accept: 'text/html, application/xhtml+xml',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Inertia': 'true',
            ...(options.headers ?? {}),
        };
        const version = this.page().version;
        if (version) headers['X-Inertia-Version'] = version;
        const xsrf = readCookie('XSRF-TOKEN');
        if (xsrf) headers['X-XSRF-TOKEN'] = xsrf;
        if (options.only?.length) {
            headers['X-Inertia-Partial-Component'] = this.page().component;
            headers['X-Inertia-Partial-Data'] = options.only.join(',');
        }

        let httpMethod = method.toUpperCase();
        const data = options.data ?? {};
        if (method === 'get') {
            target = mergeQuery(target, data instanceof FormData ? {} : data);
        } else if (data instanceof FormData || options.forceFormData || hasFiles(data)) {
            const form = data instanceof FormData ? data : toFormData(data);
            // Multipart PUT/PATCH/DELETE are sent as POST with Laravel's method spoofing.
            if (method !== 'post') {
                form.append('_method', method);
                httpMethod = 'POST';
            }
            body = form;
        } else {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(data);
        }

        options.onStart?.();
        this.processing.set(true);
        this.progress.start();

        try {
            const response = await fetch(target.href, { method: httpMethod, headers, body, credentials: 'same-origin', signal: controller.signal });

            if (response.status === 409 && response.headers.get('X-Inertia-Location')) {
                window.location.href = response.headers.get('X-Inertia-Location')!;
                return;
            }
            if (!response.headers.get('X-Inertia')) {
                // A normal (non-Inertia) response, e.g. an error page or a file.
                showResponseModal(await response.text());
                return;
            }

            const page = (await response.json()) as Page;
            if (id !== this.visitId) return;

            // Keep the URL fragment the user asked for (e.g. "#messages").
            if (target.hash && !new URL(page.url, window.location.href).hash) page.url += target.hash;

            const errors = page.props.errors ?? {};
            const hasErrors = Object.keys(errors).length > 0;
            const preserveState = resolve(options.preserveState, page) ?? false;
            const preserveScroll = resolve(options.preserveScroll, page) ?? false;
            const replace = options.replace || new URL(page.url, window.location.href).href === window.location.href;

            await this.setPage(page, { preserveState: preserveState || (hasErrors && method !== 'get') });
            if (replace) history.replaceState({ page }, '', page.url);
            else history.pushState({ page }, '', page.url);

            if (!preserveScroll) {
                if (!this.scrollToHash()) window.scrollTo(0, 0);
            }

            if (hasErrors) options.onError?.(errors);
            else options.onSuccess?.(page);
        } catch (error) {
            if ((error as Error).name !== 'AbortError') {
                console.error(error);
            }
        } finally {
            if (id === this.visitId) {
                this.processing.set(false);
                this.progress.done();
                options.onFinish?.();
            }
        }
    }

    private async setPage(page: Page, { preserveState }: { preserveState: boolean }): Promise<void> {
        const loader = pages[`../pages/${page.component}.ts`];
        if (!loader) throw new Error(`Page not found: ${page.component}`);
        const component = (await loader()).default;

        const sameComponent = this.page().component === page.component;
        this.page.set(page);
        this.component.set(component);
        if (!(sameComponent && preserveState)) this.key.update((k) => k + 1);
    }

    private scrollToHash(): boolean {
        const hash = window.location.hash.slice(1);
        if (!hash) return false;
        // Wait for the new page to render.
        setTimeout(() => document.getElementById(decodeURIComponent(hash))?.scrollIntoView(), 50);
        return true;
    }
}

function resolve(option: boolean | ((page: Page) => boolean) | undefined, page: Page): boolean | undefined {
    return typeof option === 'function' ? option(page) : option;
}

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

function hasFiles(data: unknown): boolean {
    if (data instanceof File || data instanceof Blob) return true;
    if (Array.isArray(data)) return data.some(hasFiles);
    if (data && typeof data === 'object') return Object.values(data).some(hasFiles);
    return false;
}

/** Same encoding as Inertia's objectToFormData: nested keys, arrays as [i], booleans as 1/0. */
export function toFormData(data: Record<string, unknown>, form = new FormData(), parent?: string): FormData {
    for (const [key, value] of Object.entries(data)) {
        append(form, parent ? `${parent}[${key}]` : key, value);
    }
    return form;
}

function append(form: FormData, key: string, value: unknown): void {
    if (Array.isArray(value)) {
        value.forEach((item, i) => append(form, `${key}[${i}]`, item));
    } else if (value instanceof Date) {
        form.append(key, value.toISOString());
    } else if (value instanceof File || value instanceof Blob) {
        form.append(key, value);
    } else if (typeof value === 'boolean') {
        form.append(key, value ? '1' : '0');
    } else if (value === null || value === undefined) {
        form.append(key, '');
    } else if (typeof value === 'object') {
        toFormData(value as Record<string, unknown>, form, key);
    } else {
        form.append(key, String(value));
    }
}

function mergeQuery(url: URL, data: Record<string, unknown>): URL {
    const add = (key: string, value: unknown) => {
        if (value === undefined || value === null) return;
        if (Array.isArray(value)) value.forEach((v) => add(`${key}[]`, v));
        else if (typeof value === 'object') Object.entries(value as object).forEach(([k, v]) => add(`${key}[${k}]`, v));
        else url.searchParams.append(key, String(value));
    };
    Object.entries(data).forEach(([k, v]) => add(k, v));
    return url;
}

/** Shows a non-Inertia response (usually a Laravel error page) in an overlay, like Inertia does. */
function showResponseModal(html: string): void {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;z-index:200000;background:rgba(0,0,0,.6);padding:50px;';
    const frame = document.createElement('iframe');
    frame.style.cssText = 'width:100%;height:100%;background:#fff;border-radius:6px;border:0;';
    overlay.appendChild(frame);
    overlay.addEventListener('click', () => overlay.remove());
    document.body.appendChild(overlay);
    frame.contentDocument?.open();
    frame.contentDocument?.write(html);
    frame.contentDocument?.close();
}
