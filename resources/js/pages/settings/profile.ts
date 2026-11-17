import { DeleteUser } from '@/components/delete-user';
import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { InertiaLink } from '@/inertia/link';
import { pageProps } from '@/inertia/page';
import { AppLayout } from '@/layouts/app-layout';
import { SettingsLayout } from '@/layouts/settings-layout';
import { FormField } from '@/ui/field';
import { UI } from '@/ui/primitives';
import { HeadingSmall, InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';

interface CustomerProfile {
    type: 'individual' | 'company';
    company_name: string | null;
    phone: string | null;
    gstin: string | null;
    pan: string | null;
}

@Component({
    imports: [InertiaHead, InertiaLink, AppLayout, SettingsLayout, UI, FormField, HeadingSmall, InputError, DeleteUser],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="[{ title: 'Profile settings', href: '/settings/profile' }]">
            <inertia-head title="Profile settings" />
            <settings-layout>
                <div class="space-y-6">
                    <heading-small title="Profile information" description="Update your name and email address" />

                    <form class="space-y-6" (submit)="submit($event)">
                        <div class="grid gap-2">
                            <label ui-label for="name">Name</label>
                            <input
                                ui-input
                                id="name"
                                class="mt-1 block w-full"
                                required
                                autocomplete="name"
                                placeholder="Full name"
                                [form]="form"
                                field="name"
                            />
                            <input-error class="mt-2" [message]="form.error('name')" />
                        </div>

                        <div class="grid gap-2">
                            <label ui-label for="email">Email address</label>
                            <input
                                ui-input
                                id="email"
                                type="email"
                                class="mt-1 block w-full"
                                required
                                autocomplete="username"
                                placeholder="Email address"
                                [form]="form"
                                field="email"
                            />
                            <input-error class="mt-2" [message]="form.error('email')" />
                        </div>

                        @if (props().customer) {
                            <div class="grid gap-2">
                                <label ui-label for="phone">Mobile number</label>
                                <input ui-input id="phone" placeholder="+91 98765 43210" [form]="form" field="phone" />
                                <input-error [message]="form.error('phone')" />
                            </div>

                            <div class="grid gap-2">
                                <label ui-label for="type">Account type</label>
                                <select ui-select id="type" [form]="form" field="type">
                                    <option value="individual">Individual</option>
                                    <option value="company">Company</option>
                                </select>
                            </div>

                            @if (form.data().type === 'company') {
                                <div class="grid gap-2">
                                    <label ui-label for="company_name">Company name</label>
                                    <input ui-input id="company_name" [form]="form" field="company_name" />
                                    <input-error [message]="form.error('company_name')" />
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <label ui-label for="gstin">GSTIN</label>
                                        <input
                                            ui-input
                                            id="gstin"
                                            maxlength="15"
                                            placeholder="27AAPFU0939F1ZV"
                                            [form]="form"
                                            field="gstin"
                                            [uppercase]="true"
                                        />
                                        <input-error [message]="form.error('gstin')" />
                                    </div>
                                    <div class="grid gap-2">
                                        <label ui-label for="pan">PAN</label>
                                        <input
                                            ui-input
                                            id="pan"
                                            maxlength="10"
                                            placeholder="AAPFU0939F"
                                            [form]="form"
                                            field="pan"
                                            [uppercase]="true"
                                        />
                                        <input-error [message]="form.error('pan')" />
                                    </div>
                                </div>
                            }
                        }

                        @if (props().mustVerifyEmail && props().auth.user.email_verified_at === null) {
                            <div>
                                <p class="mt-2 text-sm text-neutral-800">
                                    Your email address is unverified.
                                    <button
                                        type="button"
                                        [link]="verifyUrl"
                                        method="post"
                                        class="rounded-md text-sm text-neutral-600 underline hover:text-neutral-900 focus:ring-2 focus:ring-offset-2 focus:outline-hidden"
                                    >
                                        Click here to re-send the verification email.
                                    </button>
                                </p>
                                @if (props().status === 'verification-link-sent') {
                                    <div class="mt-2 text-sm font-medium text-green-600">
                                        A new verification link has been sent to your email address.
                                    </div>
                                }
                            </div>
                        }

                        <div class="flex items-center gap-4">
                            <button ui-button [disabled]="form.processing()">Save</button>
                            <p class="text-sm text-neutral-600 transition ease-in-out" [class.opacity-0]="!form.recentlySuccessful()">Saved</p>
                        </div>
                    </form>
                </div>

                <delete-user />
            </settings-layout>
        </app-layout>
    `,
})
export default class Profile {
    protected props = pageProps<{ mustVerifyEmail: boolean; status?: string; customer: CustomerProfile | null }>();
    protected form = this.createForm();
    protected readonly verifyUrl = route('verification.send');

    private createForm() {
        const { auth, customer } = this.props();
        return new InertiaForm({
            name: auth.user.name,
            email: auth.user.email,
            ...(customer
                ? {
                      type: customer.type,
                      company_name: customer.company_name ?? '',
                      phone: customer.phone ?? '',
                      gstin: customer.gstin ?? '',
                      pan: customer.pan ?? '',
                  }
                : {}),
        } as Record<string, unknown> & { type?: string });
    }

    submit(event: Event): void {
        event.preventDefault();
        this.form.patch(route('profile.update'));
    }
}
