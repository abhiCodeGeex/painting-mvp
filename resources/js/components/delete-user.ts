import { InertiaForm } from '@/inertia/form';
import { DIALOG_DESCRIPTION, DIALOG_FOOTER, DIALOG_TITLE, UiDialog } from '@/ui/dialog';
import { FormField } from '@/ui/field';
import { UI } from '@/ui/primitives';
import { HeadingSmall, InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component, ElementRef, signal, viewChild } from '@angular/core';

@Component({
    selector: 'delete-user',
    imports: [UiDialog, UI, FormField, HeadingSmall, InputError],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="space-y-6">
            <heading-small title="Delete account" description="Delete your account and all of its resources" />
            <div class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p class="font-medium">Warning</p>
                    <p class="text-sm">Please proceed with caution, this cannot be undone.</p>
                </div>

                <button ui-button variant="destructive" type="button" (click)="open.set(true)">Delete account</button>

                <ui-dialog [(open)]="open">
                    <h2 [class]="t">Are you sure you want to delete your account?</h2>
                    <p [class]="d">
                        Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to
                        confirm you would like to permanently delete your account.
                    </p>
                    <form class="space-y-6" (submit)="deleteUser($event)">
                        <div class="grid gap-2">
                            <label ui-label for="delete-password" class="sr-only">Password</label>
                            <input
                                #password
                                ui-input
                                id="delete-password"
                                type="password"
                                name="password"
                                placeholder="Password"
                                autocomplete="current-password"
                                [form]="form"
                                field="password"
                            />
                            <input-error [message]="form.error('password')" />
                        </div>

                        <div [class]="f">
                            <button ui-button variant="secondary" type="button" (click)="closeModal()">Cancel</button>
                            <button ui-button variant="destructive" type="submit" [disabled]="form.processing()">Delete account</button>
                        </div>
                    </form>
                </ui-dialog>
            </div>
        </div>
    `,
})
export class DeleteUser {
    protected open = signal(false);
    protected form = new InertiaForm({ password: '' });
    private password = viewChild<ElementRef<HTMLInputElement>>('password');
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;
    protected readonly f = DIALOG_FOOTER;

    deleteUser(event: Event): void {
        event.preventDefault();
        this.form.delete(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => this.closeModal(),
            onError: () => this.password()?.nativeElement.focus(),
            onFinish: () => this.form.reset(),
        });
    }

    closeModal(): void {
        this.form.clearErrors();
        this.form.reset();
        this.open.set(false);
    }
}
