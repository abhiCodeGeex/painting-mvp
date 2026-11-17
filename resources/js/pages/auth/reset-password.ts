import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { pageProps } from '@/inertia/page';
import { AuthLayout } from '@/layouts/auth-layout';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { LoaderCircle } from 'lucide';

@Component({
    imports: [InertiaHead, AuthLayout, UI, FormField, Icon, InputError],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <inertia-head title="Reset password" />
        <auth-layout title="Reset password" description="Please enter your new password below">
            <form (submit)="submit($event)">
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <label ui-label for="email">Email</label>
                        <input
                            ui-input
                            id="email"
                            type="email"
                            name="email"
                            autocomplete="email"
                            class="mt-1 block w-full"
                            readonly
                            [form]="form"
                            field="email"
                        />
                        <input-error [message]="form.error('email')" class="mt-2" />
                    </div>

                    <div class="grid gap-2">
                        <label ui-label for="password">Password</label>
                        <input
                            ui-input
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            class="mt-1 block w-full"
                            autofocus
                            placeholder="Password"
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
                            name="password_confirmation"
                            autocomplete="new-password"
                            class="mt-1 block w-full"
                            placeholder="Confirm password"
                            [form]="form"
                            field="password_confirmation"
                        />
                        <input-error [message]="form.error('password_confirmation')" class="mt-2" />
                    </div>

                    <button ui-button type="submit" class="mt-4 w-full" [disabled]="form.processing()">
                        @if (form.processing()) {
                            <svg [icon]="LoaderCircle" class="h-4 w-4 animate-spin" />
                        }
                        Reset password
                    </button>
                </div>
            </form>
        </auth-layout>
    `,
})
export default class ResetPassword {
    private props = pageProps<{ token: string; email: string }>();
    protected form = new InertiaForm({ token: this.props().token, email: this.props().email, password: '', password_confirmation: '' });
    protected readonly LoaderCircle = LoaderCircle;

    submit(event: Event): void {
        event.preventDefault();
        this.form.post(route('password.store'), { onFinish: () => this.form.reset('password', 'password_confirmation') });
    }
}
