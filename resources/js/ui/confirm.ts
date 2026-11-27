import { InertiaRouter } from '@/inertia/router';
import {
    ApplicationRef,
    ChangeDetectionStrategy,
    Component,
    createComponent,
    effect,
    EnvironmentInjector,
    inject,
    Injectable,
    input,
    signal,
    type ComponentRef,
} from '@angular/core';
import { DIALOG_DESCRIPTION, DIALOG_FOOTER, DIALOG_HEADER, DIALOG_TITLE, UiDialog } from './dialog';
import { UiButton } from './primitives';

export interface ConfirmOptions {
    title: string;
    message?: string;
    /** Label of the confirming button (e.g. "Remove"). */
    confirm?: string;
    cancel?: string;
    /** Red confirming button, for removing or deleting something. */
    destructive?: boolean;
}

/** The "Are you sure?" dialog itself; opened through the Confirm service. */
@Component({
    selector: 'ui-confirm',
    imports: [UiDialog, UiButton],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <ui-dialog [open]="open()" (openChange)="!$event && answer(false)" contentClass="sm:max-w-md">
            <div [class]="header">
                <h2 [class]="title">{{ options().title }}</h2>
                @if (options().message) {
                    <p [class]="description">{{ options().message }}</p>
                }
            </div>
            <div [class]="footer + ' gap-2 sm:gap-0'">
                <button ui-button variant="outline" type="button" (click)="answer(false)">{{ options().cancel ?? 'Cancel' }}</button>
                <button ui-button type="button" [variant]="options().destructive ? 'destructive' : 'default'" (click)="answer(true)">
                    {{ options().confirm ?? 'OK' }}
                </button>
            </div>
        </ui-dialog>
    `,
})
export class ConfirmDialog {
    readonly options = input.required<ConfirmOptions>();
    readonly done = input.required<(ok: boolean) => void>();
    protected open = signal(true);
    protected readonly header = DIALOG_HEADER;
    protected readonly title = DIALOG_TITLE;
    protected readonly description = DIALOG_DESCRIPTION;
    protected readonly footer = DIALOG_FOOTER;
    private answered = false;

    answer(ok: boolean): void {
        if (this.answered) return;
        this.answered = true;
        this.open.set(false);
        this.done()(ok);
    }
}

/**
 * Styled replacement for window.confirm(): `if (await confirm.ask({ title: 'Remove this artwork?' })) …`.
 * Escape, the backdrop and Cancel all answer "no".
 */
@Injectable({ providedIn: 'root' })
export class Confirm {
    private appRef = inject(ApplicationRef);
    private injector = inject(EnvironmentInjector);
    private router = inject(InertiaRouter);

    ask(options: ConfirmOptions): Promise<boolean> {
        return new Promise((resolve) => {
            let ref: ComponentRef<ConfirmDialog> | null = null;
            let done = (ok: boolean) => {
                resolve(ok);
                // Let the dialog close before it is removed.
                setTimeout(() => {
                    if (!ref) return;
                    this.appRef.detachView(ref.hostView);
                    ref.destroy();
                    ref = null;
                });
            };
            ref = createComponent(ConfirmDialog, { environmentInjector: this.injector });
            // Leaving the page (a link, Back) answers "no": never act on a page the customer has left.
            const startUrl = this.router.page().url;
            const watch = effect(
                () => {
                    if (this.router.page().url !== startUrl) ref?.instance.answer(false);
                },
                { injector: this.injector },
            );
            const answered = done;
            done = (ok: boolean) => {
                watch.destroy();
                answered(ok);
            };
            ref.setInput('options', options);
            ref.setInput('done', done);
            this.appRef.attachView(ref.hostView);
            document.body.appendChild(ref.location.nativeElement);
            ref.changeDetectorRef.detectChanges();
        });
    }
}
