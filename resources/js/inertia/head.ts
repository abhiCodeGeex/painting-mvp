import { Directive, effect, input } from '@angular/core';

const appName = (import.meta.env['VITE_APP_NAME'] as string | undefined) || 'Laravel';

/** <inertia-head title="Dashboard" /> sets "Dashboard - App name" (same as <Head title> in React). */
@Directive({ selector: 'inertia-head' })
export class InertiaHead {
    readonly title = input.required<string>();

    constructor() {
        effect(() => {
            document.title = `${this.title()} - ${appName}`;
        });
    }
}
