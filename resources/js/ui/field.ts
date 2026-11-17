import type { InertiaForm } from '@/inertia/form';
import { afterRenderEffect, Directive, ElementRef, HostListener, inject, input } from '@angular/core';

/**
 * Binds a native input / select / textarea to one field of an InertiaForm:
 *   <input ui-input [form]="form" field="email" />
 * (the Angular equivalent of value={data.email} onChange={e => setData('email', e.target.value)}).
 */
@Directive({ selector: '[field][form]' })
export class FormField {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    readonly form = input.required<InertiaForm<any>>();
    readonly field = input.required<string>();
    /** Upper-case what is typed (GSTIN, PAN). */
    readonly uppercase = input(false);

    private el: ElementRef<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement> = inject(ElementRef);

    constructor() {
        // After rendering, so <option>s created in the same pass already exist.
        afterRenderEffect(() => {
            const value = this.form().data()[this.field()];
            const el = this.el.nativeElement;
            const text = value === null || value === undefined ? '' : String(value);
            if (el.value !== text) el.value = text; // don't move the caret while typing
        });
    }

    @HostListener('input')
    @HostListener('change')
    onInput(): void {
        let value = this.el.nativeElement.value;
        if (this.uppercase()) {
            value = value.toUpperCase();
            this.el.nativeElement.value = value;
        }
        this.form().set(this.field(), value);
    }
}
