import { InertiaHead } from '@/inertia/head';
import { InertiaLink } from '@/inertia/link';
import { sharedProps } from '@/inertia/page';
import { AppLogoIcon } from '@/layouts/app-logo';
import { Icon } from '@/ui/icon';
import { UiButton } from '@/ui/primitives';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { Frame, Palette, Ruler } from 'lucide';

@Component({
    imports: [InertiaHead, InertiaLink, AppLogoIcon, Icon, UiButton],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <inertia-head title="Welcome" />
        <div class="bg-background text-foreground flex min-h-screen flex-col">
            <header class="mx-auto flex w-full max-w-5xl items-center justify-between p-4 sm:p-6">
                <div class="flex items-center gap-2 font-semibold">
                    <svg app-logo-icon class="size-6 fill-current" />
                    {{ props().name }}
                </div>
                <nav class="flex items-center gap-2">
                    @if (props().auth.user) {
                        <a ui-button [link]="r('dashboard')">Dashboard</a>
                    } @else {
                        <a ui-button variant="ghost" [link]="r('login')">Log in</a>
                        <a ui-button [link]="r('register')">Register</a>
                    }
                </nav>
            </header>

            <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-10 p-4 sm:p-6">
                <div class="max-w-2xl">
                    <h1 class="text-3xl font-semibold tracking-tight sm:text-5xl">Artwork and framing, quoted to your exact wall.</h1>
                    <p class="text-muted-foreground mt-4 text-lg">
                        Send one enquiry for every piece in your project and get a clear, itemised quotation.
                    </p>
                    @if (!props().auth.user) {
                        <a ui-button size="lg" class="mt-6" [link]="r('register')">Start an enquiry</a>
                    }
                </div>

                <div class="grid gap-6 sm:grid-cols-3">
                    @for (step of steps; track step.title) {
                        <div class="rounded-xl border p-5">
                            <svg [icon]="step.icon" class="text-muted-foreground mb-3 size-6" />
                            <h2 class="font-medium">{{ step.title }}</h2>
                            <p class="text-muted-foreground mt-1 text-sm">{{ step.text }}</p>
                        </div>
                    }
                </div>
            </main>
        </div>
    `,
})
export default class Welcome {
    protected props = sharedProps();
    protected r = (name: string) => route(name);
    protected readonly steps = [
        { icon: Palette, title: 'Choose your artwork', text: 'Pick from our catalog, brief us on a new creation, or bring your own piece.' },
        { icon: Ruler, title: 'Size & frame it', text: 'Set dimensions, medium, frame, mat and glazing, and preview it on your wall.' },
        { icon: Frame, title: 'Get a quotation', text: 'Receive a detailed quote you can approve, adjust or decline online.' },
    ];
}
