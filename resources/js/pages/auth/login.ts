import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { pageProps } from '@/inertia/page';
import { AuthLayout } from '@/layouts/auth-layout';
import { UiCheckbox } from '@/ui/checkbox';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError, TextLink } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { LoaderCircle } from 'lucide';

@Component({
    imports: [InertiaHead, AuthLayout, UI, FormField, UiCheckbox, Icon, InputError, TextLink],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <inertia-head title="Log in" />
        <auth-layout title="Log in to your account" description="Enter your email and password below to log in">
            <form class="flex flex-col gap-6" (submit)="submit($event)">
                <div class="grid gap-6">
                    <div class="grid gap-2">
                        <label ui-label for="email">Email address</label>
                        <input
                            ui-input
                            id="email"
                            type="email"
                            required
                            autofocus
                            tabindex="1"
                            autocomplete="email"
                            placeholder="email@example.com"
                            [form]="form"
                            field="email"
                        />
                        <input-error [message]="form.error('email')" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center">
                            <label ui-label for="password">Password</label>
                            @if (props().canResetPassword) {
                                <text-link [href]="r('password.request')" class="ml-auto text-sm" [tabIndex]="5">Forgot password?</text-link>
                            }
                        </div>
                        <input
                            ui-input
                            id="password"
                            type="password"
                            required
                            tabindex="2"
                            autocomplete="current-password"
                            placeholder="Password"
                            [form]="form"
                            field="password"
                        />
                        <input-error [message]="form.error('password')" />
                    </div>

                    <div class="flex items-center space-x-3">
                        <ui-checkbox id="remember" [checked]="form.data().remember" (checkedChange)="form.set('remember', $event)" />
                        <label ui-label for="remember">Remember me</label>
                    </div>

                    <button ui-button type="submit" class="mt-4 w-full" tabindex="4" [disabled]="form.processing()">
                        @if (form.processing()) {
                            <svg [icon]="LoaderCircle" class="h-4 w-4 animate-spin" />
                        }
                        Log in
                    </button>
                </div>

                <div class="text-muted-foreground text-center text-sm">
                    Don't have an account?
                    <text-link [href]="r('register')" [tabIndex]="5">Sign up</text-link>
                </div>
            </form>

            @if (props().status) {
                <div class="mb-4 text-center text-sm font-medium text-green-600">{{ props().status }}</div>
            }
        </auth-layout>
    `,
})
export default class Login {
    protected props = pageProps<{ status?: string; canResetPassword: boolean }>();
    protected form = new InertiaForm({ email: '', password: '', remember: false });
    protected readonly LoaderCircle = LoaderCircle;
    protected r = (name: string) => route(name);

    submit(event: Event): void {
        event.preventDefault();
        this.form.post(route('login'), { onFinish: () => this.form.reset('password') });
    }
}
