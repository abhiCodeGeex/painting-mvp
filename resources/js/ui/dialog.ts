import { cn } from '@/lib/utils';
import { A11yModule } from '@angular/cdk/a11y';
import { afterNextRender, ChangeDetectionStrategy, Component, computed, effect, ElementRef, inject, input, model, OnDestroy } from '@angular/core';
import { X } from 'lucide';
import { Icon } from './icon';

let openDialogs = 0;

/**
 * Modal dialog with the same classes as the shadcn/Radix Dialog: overlay, centred panel,
 * close button, Escape to close, focus trapped inside, page scroll locked.
 * The element is moved to <body> (like a portal) so it is never clipped by its parent.
 */
@Component({
    selector: 'ui-dialog',
    imports: [Icon, A11yModule],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (open()) {
            <div
                data-state="open"
                class="data-[state=open]:animate-in data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/80"
                (click)="close()"
            ></div>
            <div
                role="dialog"
                aria-modal="true"
                data-state="open"
                cdkTrapFocus
                [cdkTrapFocusAutoCapture]="true"
                [class]="panelClasses()"
                (keydown.escape)="close()"
            >
                <ng-content />
                <button
                    type="button"
                    class="ring-offset-background focus:ring-ring absolute top-4 right-4 rounded-sm opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none"
                    (click)="close()"
                >
                    <svg [icon]="X" class="h-4 w-4" />
                    <span class="sr-only">Close</span>
                </button>
            </div>
        }
    `,
})
export class UiDialog implements OnDestroy {
    readonly open = model(false);
    /** Extra classes for the panel (e.g. "sm:max-w-4xl"). */
    readonly contentClass = input('');
    /** Asked before the backdrop, Escape or the X closes the dialog; return false to keep it open. */
    readonly beforeClose = input<(() => boolean) | null>(null);
    protected readonly X = X;
    private host: ElementRef<HTMLElement> = inject(ElementRef);
    private locked = false;

    protected panelClasses = computed(() =>
        cn(
            'bg-background data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 fixed top-[50%] left-[50%] z-50 grid w-full max-w-lg translate-x-[-50%] translate-y-[-50%] gap-4 border p-6 shadow-lg duration-200 sm:rounded-lg',
            this.contentClass(),
        ),
    );

    constructor() {
        afterNextRender(() => document.body.appendChild(this.host.nativeElement));
        effect(() => this.lock(this.open()));
    }

    close(): void {
        const guard = this.beforeClose();
        if (guard && !guard()) return;
        this.open.set(false);
    }

    ngOnDestroy(): void {
        this.lock(false);
        this.host.nativeElement.remove();
    }

    private lock(on: boolean): void {
        if (on === this.locked) return;
        this.locked = on;
        openDialogs += on ? 1 : -1;
        document.body.style.overflow = openDialogs > 0 ? 'hidden' : '';
    }
}

/** Plain-markup helpers with the dialog header / title / description classes. */
export const DIALOG_HEADER = 'flex flex-col space-y-1.5 text-center sm:text-left';
export const DIALOG_TITLE = 'text-lg font-semibold leading-none tracking-tight';
export const DIALOG_DESCRIPTION = 'text-sm text-muted-foreground';
export const DIALOG_FOOTER = 'flex flex-col-reverse sm:flex-row sm:justify-end sm:space-x-2';
