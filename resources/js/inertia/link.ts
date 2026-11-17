import { Directive, ElementRef, HostListener, inject, input } from '@angular/core';
import { InertiaRouter } from './router';
import type { Method, VisitData } from './types';

/**
 * <a [link]="url"> or <button [link]="url" method="post"> — navigates with Inertia
 * instead of a full page load (same as <Link> from @inertiajs/react).
 */
@Directive({
    selector: '[link]',
    host: { '[attr.href]': 'isAnchor ? link() : null' },
})
export class InertiaLink {
    private router = inject(InertiaRouter);
    private el: ElementRef<HTMLElement> = inject(ElementRef);

    readonly link = input.required<string>();
    readonly method = input<Method>('get');
    readonly data = input<VisitData>({});
    readonly preserveScroll = input(false);
    readonly preserveState = input<boolean | undefined>(undefined);
    readonly replace = input(false);

    get isAnchor(): boolean {
        return this.el.nativeElement.tagName === 'A';
    }

    @HostListener('click', ['$event'])
    onClick(event: MouseEvent): void {
        if (this.isAnchor) {
            const anchor = this.el.nativeElement as HTMLAnchorElement;
            const modified = event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0;
            if (modified || anchor.target === '_blank' || event.defaultPrevented) return;
        }
        event.preventDefault();
        // Close the mobile sidebar after navigating (the React app's "mobile-navigation" event).
        window.dispatchEvent(new Event('mobile-navigation'));
        this.router.visit(this.link(), {
            method: this.method(),
            data: this.data(),
            preserveScroll: this.preserveScroll(),
            preserveState: this.preserveState() ?? this.method() !== 'get',
            replace: this.replace(),
        });
    }
}
