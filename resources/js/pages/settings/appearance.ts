import { InertiaHead } from '@/inertia/head';
import { AppLayout } from '@/layouts/app-layout';
import { SettingsLayout } from '@/layouts/settings-layout';
import { appearance, updateAppearance, type Appearance as Mode } from '@/lib/appearance';
import { Icon } from '@/ui/icon';
import { HeadingSmall } from '@/ui/text';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { Monitor, Moon, Sun } from 'lucide';

@Component({
    imports: [InertiaHead, AppLayout, SettingsLayout, Icon, HeadingSmall],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="[{ title: 'Appearance settings', href: '/settings/appearance' }]">
            <inertia-head title="Appearance settings" />
            <settings-layout>
                <div class="space-y-6">
                    <heading-small title="Appearance settings" description="Update your account's appearance settings" />
                    <div class="inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
                        @for (tab of tabs; track tab.value) {
                            <button
                                type="button"
                                [class]="
                                    'flex items-center rounded-md px-3.5 py-1.5 transition-colors ' +
                                    (current() === tab.value
                                        ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                                        : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60')
                                "
                                (click)="set(tab.value)"
                            >
                                <svg [icon]="tab.icon" class="-ml-1 h-4 w-4" />
                                <span class="ml-1.5 text-sm">{{ tab.label }}</span>
                            </button>
                        }
                    </div>
                </div>
            </settings-layout>
        </app-layout>
    `,
})
export default class Appearance {
    protected current = appearance;
    protected readonly tabs: { value: Mode; icon: typeof Sun; label: string }[] = [
        { value: 'light', icon: Sun, label: 'Light' },
        { value: 'dark', icon: Moon, label: 'Dark' },
        { value: 'system', icon: Monitor, label: 'System' },
    ];

    set(mode: Mode): void {
        updateAppearance(mode);
    }
}
