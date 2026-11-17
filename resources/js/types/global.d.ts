import type { route as routeFn } from 'ziggy-js';

declare global {
    /** Laravel route helper from Ziggy's @routes directive (same as in the React app). */
    const route: typeof routeFn;
}

export {};
