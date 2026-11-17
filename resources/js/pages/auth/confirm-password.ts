import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
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
        <inertia-head title="Confirm password" />
        <auth-layout
            title="Confirm your password"
            description="This is a secure area of the application. Please confirm your password before continuing."
        >
            <form (submit)="submit($event)">
                <div class="space-y-6">
                    <div class="grid gap-2">
                        <label ui-label for="password">Password</label>
                        <input
                            ui-input
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Password"
                            autocomplete="current-password"
                            autofocus
                            [form]="form"
                            field="password"
                        />
                        <input-error [message]="form.error('password')" />
                    </div>

                    <div class="flex items-center">
                        <button ui-button class="w-full" [disabled]="form.processing()">
                            @if (form.processing()) {
                                <svg [icon]="LoaderCircle" class="h-4 w-4 animate-spin" />
                            }
                            Confirm password
                        </button>
                    </div>
                </div>
            </form>
        </auth-layout>
    `,
})
export default class ConfirmPassword {
    protected form = new InertiaForm({ password: '' });
    protected readonly LoaderCircle = LoaderCircle;

    submit(event: Event): void {
        event.preventDefault();
        this.form.post(route('password.confirm'), { onFinish: () => this.form.reset('password') });
    }
}
