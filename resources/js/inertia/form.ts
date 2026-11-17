import { computed, inject, signal } from '@angular/core';
import { InertiaRouter } from './router';
import type { Method, Page, VisitOptions } from './types';

type Errors<T> = Partial<Record<keyof T | string, string>>;

/**
 * Angular version of Inertia's useForm(): form data, validation errors and the
 * "processing" / "recentlySuccessful" flags as signals.
 */
export class InertiaForm<T extends Record<string, unknown>> {
    private router = inject(InertiaRouter);
    private defaults: T;
    private transformer: (data: T) => Record<string, unknown> = (d) => d;
    private recentTimer: ReturnType<typeof setTimeout> | null = null;

    readonly data = signal<T>({} as T);
    readonly errors = signal<Errors<T>>({});
    readonly processing = signal(false);
    readonly wasSuccessful = signal(false);
    readonly recentlySuccessful = signal(false);
    readonly hasErrors = computed(() => Object.keys(this.errors()).length > 0);

    constructor(initial: T) {
        this.defaults = clone(initial);
        this.data.set(clone(initial));
    }

    /** Current value of one field. */
    get<K extends keyof T>(key: K): T[K] {
        return this.data()[key];
    }

    set<K extends keyof T>(key: K, value: T[K]): void {
        this.data.update((d) => ({ ...d, [key]: value }));
    }

    update(fn: (data: T) => T): void {
        this.data.update(fn);
    }

    setAll(data: T): void {
        this.data.set(clone(data));
    }

    setDefaults(data?: T): void {
        this.defaults = clone(data ?? this.data());
    }

    reset(...fields: (keyof T)[]): void {
        if (!fields.length) {
            this.data.set(clone(this.defaults));
            return;
        }
        this.data.update((d) => {
            const next = { ...d };
            for (const f of fields) next[f] = clone(this.defaults[f]);
            return next;
        });
    }

    error(key: keyof T | string): string | undefined {
        return this.errors()[key];
    }

    clearErrors(...fields: string[]): void {
        if (!fields.length) this.errors.set({});
        else
            this.errors.update((e) => {
                const next = { ...e };
                for (const f of fields) delete next[f];
                return next;
            });
    }

    transform(fn: (data: T) => Record<string, unknown>): this {
        this.transformer = fn;
        return this;
    }

    post(url: string, options: VisitOptions = {}): void {
        this.submit('post', url, options);
    }

    put(url: string, options: VisitOptions = {}): void {
        this.submit('put', url, options);
    }

    patch(url: string, options: VisitOptions = {}): void {
        this.submit('patch', url, options);
    }

    delete(url: string, options: VisitOptions = {}): void {
        this.submit('delete', url, options);
    }

    submit(method: Method, url: string, options: VisitOptions = {}): void {
        const data = this.transformer(this.data());
        this.router.visit(url, {
            preserveState: method !== 'get',
            ...options,
            method,
            data,
            onStart: () => {
                this.processing.set(true);
                this.wasSuccessful.set(false);
                this.recentlySuccessful.set(false);
                options.onStart?.();
            },
            onSuccess: (page: Page) => {
                this.clearErrors();
                this.wasSuccessful.set(true);
                this.recentlySuccessful.set(true);
                if (this.recentTimer) clearTimeout(this.recentTimer);
                this.recentTimer = setTimeout(() => this.recentlySuccessful.set(false), 2000);
                options.onSuccess?.(page);
            },
            onError: (errors) => {
                this.errors.set(errors as Errors<T>);
                options.onError?.(errors);
            },
            onFinish: () => {
                this.processing.set(false);
                options.onFinish?.();
            },
        });
    }
}

function clone<V>(value: V): V {
    if (value instanceof File || value instanceof Blob) return value;
    if (Array.isArray(value)) return value.map(clone) as V;
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, clone(v)])) as V;
    }
    return value;
}
