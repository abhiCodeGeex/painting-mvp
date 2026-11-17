import { cn } from '@/lib/utils';
import { computed, Directive, input } from '@angular/core';

/*
 * The shadcn/ui primitives from the React app, as Angular directives on native elements.
 * Classes are identical; a "class" given in a template is merged with tailwind-merge
 * exactly like cn(buttonVariants(...), className) did.
 */

const BUTTON_BASE =
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-4 [&_svg]:shrink-0';

const BUTTON_VARIANTS = {
    default: 'bg-primary text-primary-foreground hover:bg-primary/90',
    destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
    outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    link: 'text-primary underline-offset-4 hover:underline',
};

const BUTTON_SIZES = {
    default: 'h-10 px-4 py-2',
    sm: 'h-9 rounded-md px-3',
    lg: 'h-11 rounded-md px-8',
    icon: 'h-10 w-10',
};

export type ButtonVariant = keyof typeof BUTTON_VARIANTS;
export type ButtonSize = keyof typeof BUTTON_SIZES;

export function buttonClasses(variant: ButtonVariant = 'default', size: ButtonSize = 'default', extra = ''): string {
    return cn(BUTTON_BASE, BUTTON_VARIANTS[variant], BUTTON_SIZES[size], extra);
}

@Directive({
    selector: 'button[ui-button], a[ui-button], label[ui-button]',
    host: { '[class]': 'classes()' },
})
export class UiButton {
    readonly variant = input<ButtonVariant>('default');
    readonly size = input<ButtonSize>('default');
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() => buttonClasses(this.variant(), this.size(), this.userClass()));
}

@Directive({
    selector: 'input[ui-input]',
    host: { '[class]': 'classes()' },
})
export class UiInput {
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() =>
        cn(
            'border-input bg-background ring-offset-background file:text-foreground placeholder:text-muted-foreground focus-visible:ring-ring flex h-10 w-full rounded-md border px-3 py-2 text-base file:border-0 file:bg-transparent file:text-sm file:font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
            this.userClass(),
        ),
    );
}

@Directive({
    selector: 'textarea[ui-textarea]',
    host: { '[class]': 'classes()' },
})
export class UiTextarea {
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() =>
        cn(
            'border-input placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs focus-visible:ring-1 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
            this.userClass(),
        ),
    );
}

@Directive({
    selector: 'select[ui-select]',
    host: { '[class]': 'classes()' },
})
export class UiSelect {
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() =>
        cn(
            'border-input focus-visible:ring-ring flex h-9 w-full rounded-md border bg-transparent px-3 py-1 text-base shadow-xs focus-visible:ring-1 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-neutral-900',
            this.userClass(),
        ),
    );
}

@Directive({
    selector: 'label[ui-label]',
    host: { '[class]': 'classes()' },
})
export class UiLabel {
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() =>
        cn('text-sm leading-none font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-70', this.userClass()),
    );
}

@Directive({
    selector: '[ui-separator]',
    host: { '[class]': 'classes()', role: 'none' },
})
export class UiSeparator {
    readonly userClass = input('', { alias: 'class' });
    protected classes = computed(() => cn('bg-border h-[1px] w-full shrink-0', this.userClass()));
}

export const UI = [UiButton, UiInput, UiTextarea, UiSelect, UiLabel, UiSeparator] as const;
