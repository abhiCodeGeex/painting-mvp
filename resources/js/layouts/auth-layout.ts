import { InertiaLink } from '@/inertia/link';
import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { AppLogoIcon } from './app-logo';

@Component({
    selector: 'auth-layout',
    imports: [InertiaLink, AppLogoIcon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div [class]="wide() ? 'w-full max-w-xl' : 'w-full max-w-sm'">
                <div class="flex flex-col gap-8">
                    <div class="flex flex-col items-center gap-4">
                        <a [link]="homeUrl" class="flex flex-col items-center gap-2 font-medium">
                            <div class="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                <svg app-logo-icon class="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                            </div>
                            <span class="sr-only">{{ title() }}</span>
                        </a>
                        <div class="space-y-2 text-center">
                            <h1 class="text-xl font-medium">{{ title() }}</h1>
                            <p class="text-muted-foreground text-center text-sm">{{ description() }}</p>
                        </div>
                    </div>
                    <ng-content />
                </div>
            </div>
        </div>
    `,
})
export class AuthLayout {
    readonly title = input.required<string>();
    readonly description = input.required<string>();
    /** Kept for parity; the React layout was always max-w-sm. */
    readonly wide = input(false);
    protected readonly homeUrl = route('home');
}
