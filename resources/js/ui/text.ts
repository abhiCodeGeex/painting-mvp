import { InertiaLink } from '@/inertia/link';
import type { Method } from '@/inertia/types';
import { cn } from '@/lib/utils';
import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

@Component({
    selector: 'input-error',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    template: `
        @if (message()) {
            <p [class]="classes()">{{ message() }}</p>
        }
    `,
})
export class InputError {
    readonly message = input<string | undefined | null>(undefined);
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() => cn('text-sm text-red-600 dark:text-red-400', this.userClass()));
}

@Component({
    selector: 'text-link',
    imports: [InertiaLink, NgTemplateOutlet],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    // Project the content once and reuse it: an <ng-content> repeated in @if branches only fills one of them.
    template: `
        <ng-template #content><ng-content /></ng-template>
        @if (method() === 'get') {
            <a [link]="href()" [class]="classes()" [attr.tabindex]="tabIndex()"><ng-container *ngTemplateOutlet="content" /></a>
        } @else {
            <button type="button" [link]="href()" [method]="method()" [class]="classes()" [attr.tabindex]="tabIndex()">
                <ng-container *ngTemplateOutlet="content" />
            </button>
        }
    `,
})
export class TextLink {
    readonly href = input.required<string>();
    readonly method = input<Method>('get');
    readonly tabIndex = input<number | null>(null);
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() =>
        cn(
            'text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500',
            this.userClass(),
        ),
    );
}

@Component({
    selector: 'app-heading',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    template: `
        <div class="mb-8 space-y-0.5">
            <h2 class="text-xl font-semibold tracking-tight">{{ title() }}</h2>
            @if (description()) {
                <p class="text-muted-foreground text-sm">{{ description() }}</p>
            }
        </div>
    `,
})
export class Heading {
    readonly title = input.required<string>();
    readonly description = input<string>();
}

@Component({
    selector: 'heading-small',
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <header>
            <h3 class="mb-0.5 text-base font-medium">{{ title() }}</h3>
            @if (description()) {
                <p class="text-muted-foreground text-sm">{{ description() }}</p>
            }
        </header>
    `,
})
export class HeadingSmall {
    readonly title = input.required<string>();
    readonly description = input<string>();
}

const STATUS_COLORS: Record<string, string> = {
    gray: 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300',
    info: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-300',
    warning: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
    danger: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    primary: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300',
    success: 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300',
};

@Component({
    selector: 'status-badge',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    template: `<span [class]="classes()">{{ label() }}</span>`,
})
export class StatusBadge {
    readonly label = input.required<string>();
    readonly color = input.required<string>();
    protected classes = computed(() =>
        cn(
            'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
            STATUS_COLORS[this.color()] ?? STATUS_COLORS['gray'],
        ),
    );
}
