import { InertiaForm } from '@/inertia/form';
import type { Address, Option } from '@/types/portal';
import { UiCheckbox } from '@/ui/checkbox';
import { DIALOG_DESCRIPTION, DIALOG_HEADER, DIALOG_TITLE, UiDialog } from '@/ui/dialog';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component, effect, input, model, output, untracked } from '@angular/core';
import { LoaderCircle } from 'lucide';

type AddressForm = {
    kind: 'billing' | 'delivery';
    label: string;
    contact_name: string;
    phone: string;
    line1: string;
    line2: string;
    city: string;
    pin_code: string;
    state_id: string;
    access_notes: string;
    is_default: boolean;
};

function initial(address: Address | null, kind: 'billing' | 'delivery'): AddressForm {
    return {
        kind: address?.kind ?? kind,
        label: address?.label ?? '',
        contact_name: address?.contact_name ?? '',
        phone: address?.phone ?? '',
        line1: address?.line1 ?? '',
        line2: address?.line2 ?? '',
        city: address?.city ?? '',
        pin_code: address?.pin_code ?? '',
        state_id: address?.state_id ? String(address.state_id) : '',
        access_notes: address?.access_notes ?? '',
        is_default: address?.is_default ?? false,
    };
}

@Component({
    selector: 'address-dialog',
    imports: [UiDialog, UI, FormField, UiCheckbox, Icon, InputError],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <ui-dialog [(open)]="open" contentClass="max-h-[92vh] overflow-y-auto sm:max-w-lg">
            @if (open()) {
                <div [class]="h">
                    <h2 [class]="t">{{ address() ? 'Edit address' : 'New address' }}</h2>
                    <p [class]="d">Where should we deliver, or who should we bill?</p>
                </div>
                <form class="grid gap-4 sm:grid-cols-2" (submit)="submit($event)">
                    <div class="grid gap-1.5">
                        <label ui-label>Type</label>
                        <select ui-select [form]="form" field="kind">
                            <option value="delivery">Delivery</option>
                            <option value="billing">Billing</option>
                        </select>
                        <input-error [message]="form.error('kind')" />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>Label (optional)</label>
                        <input ui-input placeholder="Home, Office, Hotel site" [form]="form" field="label" />
                        <input-error [message]="form.error('label')" />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>Contact person</label>
                        <input ui-input [form]="form" field="contact_name" />
                        <input-error [message]="form.error('contact_name')" />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>Phone</label>
                        <input ui-input placeholder="+91 98765 43210" [form]="form" field="phone" />
                        <input-error [message]="form.error('phone')" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <label ui-label>Address line 1</label>
                        <input ui-input required [form]="form" field="line1" />
                        <input-error [message]="form.error('line1')" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <label ui-label>Address line 2 (optional)</label>
                        <input ui-input [form]="form" field="line2" />
                        <input-error [message]="form.error('line2')" />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>City</label>
                        <input ui-input required [form]="form" field="city" />
                        <input-error [message]="form.error('city')" />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>PIN code</label>
                        <input ui-input inputmode="numeric" maxlength="6" required [form]="form" field="pin_code" />
                        <input-error [message]="form.error('pin_code')" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <label ui-label>State</label>
                        <select ui-select required [form]="form" field="state_id">
                            <option value="">Choose a state</option>
                            @for (s of states(); track s.value) {
                                <option [value]="s.value">{{ s.label }}</option>
                            }
                        </select>
                        <input-error [message]="form.error('state_id')" />
                    </div>
                    <div class="grid gap-1.5 sm:col-span-2">
                        <label ui-label>Access notes (optional)</label>
                        <input ui-input placeholder="e.g. No lift, 3rd floor" [form]="form" field="access_notes" />
                        <input-error [message]="form.error('access_notes')" />
                    </div>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <ui-checkbox [checked]="form.data().is_default" (checkedChange)="form.set('is_default', $event)" />
                        Use as my default {{ form.data().kind }} address
                    </label>
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <button ui-button type="button" variant="outline" (click)="open.set(false)">Cancel</button>
                        <button ui-button type="submit" [disabled]="form.processing()">
                            @if (form.processing()) {
                                <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                            }
                            Save address
                        </button>
                    </div>
                </form>
            }
        </ui-dialog>
    `,
})
export class AddressDialog {
    readonly open = model(false);
    readonly address = input<Address | null>(null);
    readonly states = input.required<Option<number>[]>();
    readonly defaultKind = input<'billing' | 'delivery'>('delivery');
    readonly saved = output<void>();

    protected form = new InertiaForm<AddressForm>(initial(null, 'delivery'));
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly h = DIALOG_HEADER;
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;

    constructor() {
        // Fresh values every time the dialog opens.
        effect(() => {
            if (!this.open()) return;
            const fresh = initial(this.address(), this.defaultKind());
            untracked(() => {
                this.form.setDefaults(fresh);
                this.form.setAll(fresh);
                this.form.clearErrors();
            });
        });
    }

    submit(event: Event): void {
        event.preventDefault();
        const address = this.address();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                this.saved.emit();
                this.open.set(false);
            },
        };
        if (address) this.form.put(route('addresses.update', address.id), options);
        else this.form.post(route('addresses.store'), options);
    }
}
