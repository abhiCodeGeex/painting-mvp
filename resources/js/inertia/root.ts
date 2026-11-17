import { NgComponentOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { InertiaRouter } from './router';

/**
 * Renders the current Inertia page. The @for over [key] re-creates the page component
 * for a fresh visit and keeps it (with its local state) when preserveState is used.
 */
@Component({
    selector: 'inertia-root',
    imports: [NgComponentOutlet],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @for (k of [router.key()]; track k) {
            @if (router.component(); as page) {
                <ng-container *ngComponentOutlet="page" />
            }
        }
    `,
})
export class InertiaRoot {
    protected router = inject(InertiaRouter);
}
