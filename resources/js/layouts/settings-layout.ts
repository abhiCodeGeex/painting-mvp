import { InertiaLink } from '@/inertia/link';
import { buttonClasses, UiSeparator } from '@/ui/primitives';
import { Heading } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';

const ITEMS = [
    { title: 'Profile', url: '/settings/profile' },
    { title: 'Addresses', url: '/settings/addresses' },
    { title: 'Password', url: '/settings/password' },
    { title: 'Appearance', url: '/settings/appearance' },
];

@Component({
    selector: 'settings-layout',
    imports: [InertiaLink, Heading, UiSeparator],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="px-4 py-6">
            <app-heading title="Settings" description="Manage your profile and account settings" />

            <div class="flex flex-col space-y-8 lg:flex-row lg:space-y-0 lg:space-x-12">
                <aside class="w-full max-w-xl lg:w-48">
                    <nav class="flex flex-col space-y-1 space-x-0">
                        @for (item of items; track item.url) {
                            <a [link]="item.url" [class]="itemClass(item.url)">{{ item.title }}</a>
                        }
                    </nav>
                </aside>

                <div ui-separator class="my-6 md:hidden"></div>

                <div class="flex-1 md:max-w-2xl">
                    <section class="max-w-xl space-y-12"><ng-content /></section>
                </div>
            </div>
        </div>
    `,
})
export class SettingsLayout {
    protected readonly items = ITEMS;

    protected itemClass(url: string): string {
        return buttonClasses('ghost', 'sm', 'w-full justify-start' + (window.location.pathname === url ? ' bg-muted' : ''));
    }
}
