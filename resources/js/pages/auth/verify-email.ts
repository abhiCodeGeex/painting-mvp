import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { pageProps } from '@/inertia/page';
import { AuthLayout } from '@/layouts/auth-layout';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { TextLink } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { LoaderCircle } from 'lucide';

@Component({
    imports: [InertiaHead, AuthLayout, UI, Icon, TextLink],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <inertia-head title="Email verification" />
        <auth-layout title="Verify email" description="Please verify your email address by clicking on the link we just emailed to you.">
            @if (props().status === 'verification-link-sent') {
                <div class="mb-4 text-center text-sm font-medium text-green-600">
                    A new verification link has been sent to the email address you provided during registration.
                </div>
            }

            <form class="space-y-6 text-center" (submit)="submit($event)">
                <button ui-button variant="secondary" [disabled]="form.processing()">
                    @if (form.processing()) {
                        <svg [icon]="LoaderCircle" class="h-4 w-4 animate-spin" />
                    }
                    Resend verification email
                </button>

                <text-link [href]="logoutUrl" method="post" class="mx-auto block text-sm">Log out</text-link>
            </form>
        </auth-layout>
    `,
})
export default class VerifyEmail {
    protected props = pageProps<{ status?: string }>();
    protected form = new InertiaForm({});
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly logoutUrl = route('logout');

    submit(event: Event): void {
        event.preventDefault();
        this.form.post(route('verification.send'));
    }
}
