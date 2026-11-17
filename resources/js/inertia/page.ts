import type { SharedData } from '@/types';
import { computed, inject } from '@angular/core';
import { InertiaRouter } from './router';

/**
 * Typed access to the current page's props inside a page component:
 *   protected props = pageProps<{ enquiries: EnquirySummary[] }>();
 *   ... props().enquiries
 */
export function pageProps<P>() {
    const router = inject(InertiaRouter);
    return computed(() => router.props() as unknown as P & SharedData & { errors: Record<string, string> });
}

/** Shared props (auth, flash, name) — same as usePage<SharedData>().props. */
export function sharedProps() {
    return pageProps<SharedData>();
}
