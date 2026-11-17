import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { AuthLayout } from '@/layouts/auth-layout';
import { UiCheckbox } from '@/ui/checkbox';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError, TextLink } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { Building2, LoaderCircle, User } from 'lucide';

@Component({
    imports: [InertiaHead, AuthLayout, UI, FormField, UiCheckbox, Icon, InputError, TextLink],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <inertia-head title="Register" />
        <auth-layout title="Create your account" description="Request artworks and framing, and track your quotations">
            <form class="flex flex-col gap-5" (submit)="submit($event)">
                <div class="grid grid-cols-2 gap-2">
                    @for (o of types; track o.value) {
                        <button
                            type="button"
                            [class]="
                                'flex items-center justify-center gap-2 rounded-lg border p-3 text-sm transition ' +
                                (form.data().type === o.value ? 'border-primary ring-primary ring-2' : 'hover:bg-muted')
                            "
                            (click)="form.set('type', o.value)"
                        >
                            <svg [icon]="o.icon" class="size-4" /> {{ o.label }}
                        </button>
                    }
                </div>

                @if (form.data().type === 'company') {
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <label ui-label for="company_name">Company name</label>
                            <input ui-input id="company_name" required [disabled]="form.processing()" [form]="form" field="company_name" />
                            <input-error [message]="form.error('company_name')" />
                        </div>
                        <div class="grid gap-2">
                            <label ui-label for="gstin">GSTIN (optional)</label>
                            <input
                                ui-input
                                id="gstin"
                                maxlength="15"
                                placeholder="27AAPFU0939F1ZV"
                                [disabled]="form.processing()"
                                [form]="form"
                                field="gstin"
                                [uppercase]="true"
                            />
                            <input-error [message]="form.error('gstin')" />
                        </div>
                    </div>
                }

                <div class="grid gap-2">
                    <label ui-label for="name">{{ form.data().type === 'company' ? 'Your name' : 'Full name' }}</label>
                    <input ui-input id="name" required autofocus autocomplete="name" [disabled]="form.processing()" [form]="form" field="name" />
                    <input-error [message]="form.error('name')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <label ui-label for="email">Email address</label>
                        <input
                            ui-input
                            id="email"
                            type="email"
                            required
                            autocomplete="email"
                            placeholder="email@example.com"
                            [disabled]="form.processing()"
                            [form]="form"
                            field="email"
                        />
                        <input-error [message]="form.error('email')" />
                    </div>
                    <div class="grid gap-2">
                        <label ui-label for="phone">Mobile number</label>
                        <input
                            ui-input
                            id="phone"
                            type="tel"
                            required
                            autocomplete="tel"
                            placeholder="+91 98765 43210"
                            [disabled]="form.processing()"
                            [form]="form"
                            field="phone"
                        />
                        <input-error [message]="form.error('phone')" />
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <label ui-label for="password">Password</label>
                        <input
                            ui-input
                            id="password"
                            type="password"
                            required
                            autocomplete="new-password"
                            [disabled]="form.processing()"
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
                            required
                            autocomplete="new-password"
                            [disabled]="form.processing()"
                            [form]="form"
                            field="password_confirmation"
                        />
                        <input-error [message]="form.error('password_confirmation')" />
                    </div>
                </div>

                <div class="space-y-2 text-sm">
                    <label class="flex items-start gap-2">
                        <ui-checkbox [checked]="form.data().terms" (checkedChange)="form.set('terms', $event)" />
                        <span>I accept the terms of service and privacy policy.</span>
                    </label>
                    <input-error [message]="form.error('terms')" />
                    <label class="text-muted-foreground flex items-start gap-2">
                        <ui-checkbox [checked]="form.data().marketing" (checkedChange)="form.set('marketing', $event)" />
                        <span>Send me occasional news about new collections (optional).</span>
                    </label>
                </div>

                <button ui-button type="submit" class="w-full" [disabled]="form.processing()">
                    @if (form.processing()) {
                        <svg [icon]="LoaderCircle" class="h-4 w-4 animate-spin" />
                    }
                    Create account
                </button>

                <div class="text-muted-foreground text-center text-sm">
                    Already have an account? <text-link [href]="r('login')">Log in</text-link>
                </div>
            </form>
        </auth-layout>
    `,
})
export default class Register {
    protected form = new InertiaForm({
        type: 'individual' as 'individual' | 'company',
        name: '',
        company_name: '',
        gstin: '',
        phone: '',
        email: '',
        password: '',
        password_confirmation: '',
        terms: false,
        marketing: false,
    });
    protected readonly types = [
        { value: 'individual' as const, label: 'For my home', icon: User },
        { value: 'company' as const, label: 'For a business', icon: Building2 },
    ];
    protected readonly LoaderCircle = LoaderCircle;
    protected r = (name: string) => route(name);

    submit(event: Event): void {
        event.preventDefault();
        this.form.post(route('register'), { onFinish: () => this.form.reset('password', 'password_confirmation') });
    }
}
