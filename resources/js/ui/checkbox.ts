import { cn } from '@/lib/utils';
import { ChangeDetectionStrategy, Component, computed, input, model } from '@angular/core';
import { Check } from 'lucide';
import { Icon } from './icon';

/** Same look and behaviour as the Radix checkbox in the React app. */
@Component({
    selector: 'ui-checkbox',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    template: `
        <button
            type="button"
            role="checkbox"
            [id]="inputId() || null"
            [attr.aria-checked]="checked()"
            [attr.data-state]="checked() ? 'checked' : 'unchecked'"
            [disabled]="disabled()"
            [class]="classes()"
            (click)="checked.set(!checked())"
        >
            @if (checked()) {
                <span class="flex items-center justify-center text-current"><svg [icon]="Check" class="size-3.5 stroke-[3]" /></span>
            }
        </button>
    `,
})
export class UiCheckbox {
    readonly checked = model(false);
    readonly disabled = input(false);
    readonly inputId = input('', { alias: 'id' });
    readonly userClass = input('', { alias: 'class' });
    protected readonly Check = Check;
    protected classes = computed(() =>
        cn(
            'peer border-input ring-offset-background focus-visible:ring-ring data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:border-accent-foreground size-5 shrink-0 rounded-sm border focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden disabled:cursor-not-allowed disabled:opacity-50',
            this.userClass(),
        ),
    );
}
