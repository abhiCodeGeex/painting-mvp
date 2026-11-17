import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { pageProps } from '@/inertia/page';
import { AuthLayout } from '@/layouts/auth-layout';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError, TextLink } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { LoaderCircle } from 'lucide';

@Component({
    imports: [InertiaHead, AuthLayout, UI, FormField, Icon, InputError, TextLink],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <inertia-head title="Forgot password" />
        <auth-layout title="Forgot password" description="Enter your email to receive a password reset link">
            @if (props().status) {
                <div class="mb-4 text-center text-sm font-medium text-green-600">{{ props().status }}</div>
            }

            <div class="space-y-6">
                <form (submit)="submit($event)">
                    <div class="grid gap-2">
                        <label ui-label for="email">Email address</label>
                        <input
                            ui-input
                            id="email"
                            type="email"
                            name="email"
                            autocomplete="off"
                            autofocus
                            placeholder="email@example.com"
                            [form]="form"
                            field="email"
                        />
                        <input-error [message]="form.error('email')" />
                    </div>

                    <div class="my-6 flex items-center justify-start">
                        <button ui-button class="w-full" [disabled]="form.processing()">
                            @if (form.processing()) {
                                <svg [icon]="LoaderCircle" class="h-4 w-4 animate-spin" />
                            }
                            Email password reset link
                        </button>
                    </div>
                </form>

                <div class="text-muted-foreground space-x-1 text-center text-sm">
                    <span>Or, return to</span>
                    <text-link [href]="loginUrl">log in</text-link>
                </div>
            </div>
        </auth-layout>
    `,
})
export default class ForgotPassword {
    protected props = pageProps<{ status?: string }>();
    protected form = new InertiaForm({ email: '' });
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly loginUrl = route('login');

    submit(event: Event): void {
        event.preventDefault();
        this.form.post(route('password.email'));
    }
}
