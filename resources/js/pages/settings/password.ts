import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { AppLayout } from '@/layouts/app-layout';
import { SettingsLayout } from '@/layouts/settings-layout';
import { FormField } from '@/ui/field';
import { UI } from '@/ui/primitives';
import { HeadingSmall, InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component, ElementRef, viewChild } from '@angular/core';

@Component({
    imports: [InertiaHead, AppLayout, SettingsLayout, UI, FormField, HeadingSmall, InputError],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="[{ title: 'Password settings', href: '/settings/password' }]">
            <inertia-head title="Profile settings" />
            <settings-layout>
                <div class="space-y-6">
                    <heading-small title="Update password" description="Ensure your account is using a long, random password to stay secure" />

                    <form class="space-y-6" (submit)="updatePassword($event)">
                        <div class="grid gap-2">
                            <label ui-label for="current_password">Current password</label>
                            <input
                                #current
                                ui-input
                                id="current_password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="current-password"
                                placeholder="Current password"
                                [form]="form"
                                field="current_password"
                            />
                            <input-error [message]="form.error('current_password')" />
                        </div>

                        <div class="grid gap-2">
                            <label ui-label for="password">New password</label>
                            <input
                                #password
                                ui-input
                                id="password"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                                placeholder="New password"
                                [form]="form"
                                field="password"
                            />
                            <input-error [message]="form.error('password')" />
                        </div>

                        <div class="grid gap-2">
                            <label ui-label for="password_confirmation">Confirm password</label>
                            <input
                                ui-input
                                id="password_confirmation"
                                type="password"
                                class="mt-1 block w-full"
                                autocomplete="new-password"
                                placeholder="Confirm password"
                                [form]="form"
                                field="password_confirmation"
                            />
                            <input-error [message]="form.error('password_confirmation')" />
                        </div>

                        <div class="flex items-center gap-4">
                            <button ui-button [disabled]="form.processing()">Save password</button>
                            <p class="text-sm text-neutral-600 transition ease-in-out" [class.opacity-0]="!form.recentlySuccessful()">Saved</p>
                        </div>
                    </form>
                </div>
            </settings-layout>
        </app-layout>
    `,
})
export default class Password {
    protected form = new InertiaForm({ current_password: '', password: '', password_confirmation: '' });
    private current = viewChild<ElementRef<HTMLInputElement>>('current');
    private password = viewChild<ElementRef<HTMLInputElement>>('password');

    updatePassword(event: Event): void {
        event.preventDefault();
        this.form.put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => this.form.reset(),
            onError: (errors) => {
                if (errors['password']) {
                    this.form.reset('password', 'password_confirmation');
                    this.password()?.nativeElement.focus();
                }
                if (errors['current_password']) {
                    this.form.reset('current_password');
                    this.current()?.nativeElement.focus();
                }
            },
        });
    }
}
