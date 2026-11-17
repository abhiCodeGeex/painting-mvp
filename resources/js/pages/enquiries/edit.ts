import { AddressDialog } from '@/components/address-dialog';
import { LineCard } from '@/components/enquiry/line-card';
import { LineEditor } from '@/components/enquiry/line-editor';
import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { pageProps } from '@/inertia/page';
import { InertiaRouter } from '@/inertia/router';
import { AppLayout } from '@/layouts/app-layout';
import { formatDate, formatInrRange } from '@/lib/units';
import type { Address, CatalogOptions, EnquiryDetail, EnquiryEstimate, EnquiryLine, ProjectOptions } from '@/types/portal';
import { UiCheckbox } from '@/ui/checkbox';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component, computed, effect, inject, input, output, signal, untracked } from '@angular/core';
import { ArrowLeft, ArrowRight, Check, Copy, LoaderCircle, Pencil, Plus, Send, Trash2 } from 'lucide';

interface Props {
    enquiry: EnquiryDetail;
    options: CatalogOptions;
    projectOptions: ProjectOptions;
    addresses: Address[];
    estimate: EnquiryEstimate;
}

/* ---------------------------- estimate total ---------------------------- */

@Component({
    selector: 'estimate-total',
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (estimate().mode !== 'hidden' && estimate().subtotal_high) {
            <div class="bg-muted/40 ml-auto w-full max-w-sm space-y-1 rounded-xl border p-4 text-sm">
                <div class="flex justify-between gap-4">
                    <span class="text-muted-foreground">Estimated subtotal</span>
                    <span class="tabular-nums">{{ range(estimate().subtotal_low, estimate().subtotal_high) }}</span>
                </div>
                @if (withTax()) {
                    <div class="flex justify-between gap-4">
                        <span class="text-muted-foreground">{{
                            estimate().intra_state ? 'CGST ' + half() + '% + SGST ' + half() + '%' : 'IGST ' + estimate().tax_rate + '%'
                        }}</span>
                        <span class="tabular-nums">{{ range(estimate().tax_low, estimate().tax_high) }}</span>
                    </div>
                    <div class="flex justify-between gap-4 border-t pt-1 font-semibold">
                        <span>Estimated total</span>
                        <span class="tabular-nums">{{ range(estimate().total_low, estimate().total_high) }}</span>
                    </div>
                }
                <p class="text-muted-foreground pt-1 text-xs">
                    Indicative only{{ withTax() ? '' : ', before GST' }}. Delivery, packing and installation are added in your quotation, which is
                    final.
                </p>
            </div>
        }
    `,
})
export class EstimateTotal {
    readonly estimate = input.required<EnquiryEstimate>();
    readonly withTax = input(false);
    protected half = computed(() => (this.estimate().tax_rate ?? 0) / 2);
    protected readonly range = formatInrRange;
}

/* ---------------------------- step 1: project ---------------------------- */

@Component({
    selector: 'project-step',
    imports: [UI, FormField, UiCheckbox, Icon, InputError, AddressDialog],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <form class="space-y-6 rounded-xl border p-5 md:p-6" (submit)="submit($event)">
            <div>
                <h2 class="text-lg font-semibold">Tell us about your project</h2>
                <p class="text-muted-foreground text-sm">This helps us prepare the right quotation.</p>
            </div>

            <div class="grid gap-5 md:grid-cols-2">
                <div class="grid gap-1.5">
                    <label ui-label>Project name</label>
                    <input ui-input placeholder="e.g. New apartment, Hotel lobby" required [form]="form" field="project_name" />
                    <input-error [message]="form.error('project_name')" />
                </div>
                <div class="grid gap-1.5">
                    <label ui-label>Type of space</label>
                    <select ui-select [form]="form" field="project_type">
                        @for (o of projectOptions().project_types; track o.value) {
                            <option [value]="o.value">{{ o.label }}</option>
                        }
                    </select>
                    <input-error [message]="form.error('project_type')" />
                </div>

                <div class="md:col-span-2">
                    <div class="grid gap-1.5">
                        <label ui-label>Delivery address</label>
                        @if (addresses().length > 0) {
                            <div class="grid gap-2 sm:grid-cols-2">
                                @for (a of addresses(); track a.id) {
                                    <button
                                        type="button"
                                        [class]="
                                            'rounded-lg border p-3 text-left text-sm transition ' +
                                            (form.data().delivery_address_id === '' + a.id ? 'border-primary ring-primary ring-2' : 'hover:bg-muted')
                                        "
                                        (click)="form.set('delivery_address_id', '' + a.id)"
                                    >
                                        <div class="font-medium">{{ a.label || a.city }}</div>
                                        <div class="text-muted-foreground text-xs">{{ a.one_line }}</div>
                                    </button>
                                }
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:bg-muted flex items-center justify-center gap-2 rounded-lg border border-dashed p-3 text-sm"
                                    (click)="addressOpen.set(true)"
                                >
                                    <svg [icon]="Plus" class="size-4" /> Add another address
                                </button>
                            </div>
                        } @else {
                            <div>
                                <button ui-button type="button" variant="outline" (click)="addressOpen.set(true)">
                                    <svg [icon]="Plus" class="size-4" /> Add a delivery address
                                </button>
                            </div>
                        }
                        <input-error [message]="form.error('delivery_address_id')" />
                    </div>
                </div>

                <div class="grid gap-1.5">
                    <label ui-label>When do you need it? (optional)</label>
                    <input ui-input type="date" [form]="form" field="required_by" />
                    <input-error [message]="form.error('required_by')" />
                </div>
                <div class="grid gap-1.5">
                    <label ui-label>Budget (optional)</label>
                    <select ui-select [form]="form" field="budget_range">
                        <option value="">Prefer not to say</option>
                        @for (o of projectOptions().budget_ranges; track o.value) {
                            <option [value]="o.value">{{ o.label }}</option>
                        }
                    </select>
                    <input-error [message]="form.error('budget_range')" />
                </div>
                <div class="grid gap-1.5">
                    <label ui-label>How should we contact you?</label>
                    <select ui-select [form]="form" field="preferred_contact">
                        @for (o of projectOptions().preferred_contacts; track o.value) {
                            <option [value]="o.value">{{ o.label }}</option>
                        }
                    </select>
                    <input-error [message]="form.error('preferred_contact')" />
                </div>
                <label class="flex items-center gap-2 self-end pb-2 text-sm">
                    <ui-checkbox [checked]="form.data().installation_needed" (checkedChange)="form.set('installation_needed', $event)" />
                    Please include installation in the quote
                </label>
                <div class="md:col-span-2">
                    <div class="grid gap-1.5">
                        <label ui-label>Notes for our team (optional)</label>
                        <textarea ui-textarea rows="3" placeholder="Deadlines, site access, style ideas…" [form]="form" field="notes"></textarea>
                        <input-error [message]="form.error('notes')" />
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button ui-button type="submit" [disabled]="form.processing()">
                    @if (form.processing()) {
                        <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                    }
                    Save & continue <svg [icon]="ArrowRight" class="size-4" />
                </button>
            </div>

            <address-dialog [(open)]="addressOpen" [address]="null" [states]="projectOptions().states" />
        </form>
    `,
})
export class ProjectStep {
    readonly enquiry = input.required<EnquiryDetail>();
    readonly projectOptions = input.required<ProjectOptions>();
    readonly addresses = input.required<Address[]>();
    readonly done = output<void>();

    private router = inject(InertiaRouter);
    protected addressOpen = signal(false);
    protected form = new InertiaForm({
        project_name: '',
        project_type: 'home',
        delivery_address_id: '',
        required_by: '',
        budget_range: '',
        installation_needed: false,
        preferred_contact: 'email',
        notes: '',
    });
    protected readonly Plus = Plus;
    protected readonly ArrowRight = ArrowRight;
    protected readonly LoaderCircle = LoaderCircle;

    constructor() {
        let initialised = false;
        effect(() => {
            const e = this.enquiry();
            const addresses = this.addresses();
            if (initialised) return;
            initialised = true;
            untracked(() =>
                this.form.setAll({
                    project_name: e.project_name,
                    project_type: e.project_type ?? 'home',
                    delivery_address_id: e.delivery_address_id ? String(e.delivery_address_id) : addresses[0] ? String(addresses[0].id) : '',
                    required_by: e.required_by ?? '',
                    budget_range: e.budget_range ?? '',
                    installation_needed: e.installation_needed,
                    preferred_contact: e.preferred_contact ?? 'email',
                    notes: e.notes ?? '',
                }),
            );
        });

        // A newly added address is selected automatically.
        effect(() => {
            const id = (this.router.page().props as { flash?: { created_address_id?: number | null } }).flash?.created_address_id;
            if (id) untracked(() => this.form.set('delivery_address_id', String(id)));
        });
    }

    submit(event: Event): void {
        event.preventDefault();
        this.form.put(route('portal.enquiries.update', this.enquiry().id), { preserveScroll: true, onSuccess: () => this.done.emit() });
    }
}

/* ---------------------------- the page ---------------------------- */

@Component({
    imports: [InertiaHead, AppLayout, UI, Icon, InputError, LineCard, LineEditor, ProjectStep, EstimateTotal],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="breadcrumbs()">
            <inertia-head title="New enquiry" />
            <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">{{ enquiry().project_name }}</h1>
                        <p class="text-muted-foreground text-sm">
                            Draft — saved automatically as you go. Nothing is sent until you press “Send enquiry”.
                        </p>
                    </div>
                    <button ui-button variant="ghost" size="sm" type="button" class="text-muted-foreground" (click)="deleteDraft()">
                        <svg [icon]="Trash2" class="size-4" /> Delete draft
                    </button>
                </div>

                <ol class="grid grid-cols-3 gap-2">
                    @for (label of steps; track label; let i = $index) {
                        <li>
                            <button
                                type="button"
                                [class]="
                                    'flex w-full items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition ' +
                                    (i === step() ? 'border-primary bg-primary/5 font-medium' : 'text-muted-foreground hover:bg-muted')
                                "
                                (click)="step.set(i)"
                            >
                                <span
                                    [class]="
                                        'flex size-6 shrink-0 items-center justify-center rounded-full text-xs ' +
                                        (i < step() ? 'bg-green-600 text-white' : i === step() ? 'bg-primary text-primary-foreground' : 'bg-muted')
                                    "
                                >
                                    @if (i < step()) {
                                        <svg [icon]="Check" class="size-3.5" />
                                    } @else {
                                        {{ i + 1 }}
                                    }
                                </span>
                                <span class="truncate">{{ label }}</span>
                            </button>
                        </li>
                    }
                </ol>

                @switch (step()) {
                    @case (0) {
                        <project-step
                            [enquiry]="enquiry()"
                            [projectOptions]="props().projectOptions"
                            [addresses]="props().addresses"
                            (done)="step.set(1)"
                        />
                    }
                    @case (1) {
                        <div class="space-y-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <h2 class="text-lg font-semibold">Artworks in this enquiry</h2>
                                    <p class="text-muted-foreground text-sm">
                                        Add every piece you need — each can be a catalog artwork, a new creation or your own artwork.
                                    </p>
                                </div>
                                <button ui-button type="button" (click)="openEditor(null)"><svg [icon]="Plus" class="size-4" /> Add artwork</button>
                            </div>

                            @if (enquiry().lines.length === 0) {
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:bg-muted flex w-full flex-col items-center gap-2 rounded-xl border border-dashed p-10"
                                    (click)="openEditor(null)"
                                >
                                    <svg [icon]="Plus" class="size-8" />
                                    <span class="font-medium">Add your first artwork</span>
                                    <span class="text-xs">You'll see a live preview on a wall as you choose.</span>
                                </button>
                            } @else {
                                <div class="grid gap-3 lg:grid-cols-2">
                                    @for (line of enquiry().lines; track line.id; let i = $index) {
                                        <line-card [line]="line" [options]="props().options" [index]="i" [estimate]="lineEstimate(line.id)">
                                            <button ui-button size="sm" variant="outline" type="button" (click)="openEditor(line)">
                                                <svg [icon]="Pencil" class="size-3.5" /> Edit
                                            </button>
                                            <button ui-button size="sm" variant="ghost" type="button" (click)="duplicate(line)">
                                                <svg [icon]="Copy" class="size-3.5" /> Duplicate
                                            </button>
                                            <button ui-button size="sm" variant="ghost" type="button" class="text-red-600" (click)="removeLine(line)">
                                                <svg [icon]="Trash2" class="size-3.5" /> Remove
                                            </button>
                                        </line-card>
                                    }
                                </div>
                            }

                            @if (enquiry().lines.length > 0) {
                                <estimate-total [estimate]="props().estimate" />
                            }

                            <div class="flex justify-between pt-2">
                                <button ui-button variant="outline" type="button" (click)="step.set(0)">
                                    <svg [icon]="ArrowLeft" class="size-4" /> Project
                                </button>
                                <button ui-button type="button" [disabled]="enquiry().lines.length === 0" (click)="step.set(2)">
                                    Review <svg [icon]="ArrowRight" class="size-4" />
                                </button>
                            </div>

                            <line-editor [(open)]="editorOpen" [enquiryId]="enquiry().id" [line]="editing()" [options]="props().options" />
                        </div>
                    }
                    @case (2) {
                        <div class="space-y-5">
                            <div class="grid gap-4 rounded-xl border p-5 md:grid-cols-2">
                                @for (s of summary(); track s.label) {
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <div class="text-muted-foreground text-xs">{{ s.label }}</div>
                                            <div class="text-sm">{{ s.value }}</div>
                                        </div>
                                        <button
                                            type="button"
                                            class="text-muted-foreground hover:text-foreground"
                                            title="Change"
                                            (click)="step.set(0)"
                                        >
                                            <svg [icon]="Pencil" class="size-3.5" />
                                        </button>
                                    </div>
                                }
                            </div>

                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-lg font-semibold">
                                        {{ enquiry().lines.length }} {{ enquiry().lines.length === 1 ? 'artwork' : 'artworks' }}
                                    </h2>
                                    <button ui-button variant="ghost" size="sm" type="button" (click)="step.set(1)">
                                        <svg [icon]="Pencil" class="size-3.5" /> Change
                                    </button>
                                </div>
                                <div class="grid gap-3 lg:grid-cols-2">
                                    @for (line of enquiry().lines; track line.id; let i = $index) {
                                        <line-card [line]="line" [options]="props().options" [index]="i" [estimate]="lineEstimate(line.id)" />
                                    }
                                </div>
                                <estimate-total [estimate]="props().estimate" [withTax]="!!enquiry().delivery_address" />
                            </div>

                            @if (missing().length > 0 || errorList().length > 0) {
                                <div
                                    class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                                >
                                    @if (missing().length > 0) {
                                        <p>Before sending, please add {{ missing().join(' and ') }}.</p>
                                    }
                                    @for (e of errorList(); track e) {
                                        <input-error [message]="e" />
                                    }
                                </div>
                            }

                            <div class="flex flex-col-reverse justify-between gap-3 border-t pt-4 sm:flex-row sm:items-center">
                                <button ui-button variant="outline" type="button" (click)="step.set(1)">
                                    <svg [icon]="ArrowLeft" class="size-4" /> Artworks
                                </button>
                                <div class="flex flex-col items-end gap-1">
                                    <button ui-button size="lg" type="button" [disabled]="sending() || missing().length > 0" (click)="send()">
                                        @if (sending()) {
                                            <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                                        } @else {
                                            <svg [icon]="Send" class="size-4" />
                                        }
                                        Send enquiry
                                    </button>
                                    <span class="text-muted-foreground text-xs">We'll review it and reply, usually within one working day.</span>
                                </div>
                            </div>
                        </div>
                    }
                }
            </div>
        </app-layout>
    `,
})
export default class EditEnquiry {
    protected props = pageProps<Props>();
    private router = inject(InertiaRouter);
    protected enquiry = computed(() => this.props().enquiry);
    protected step = signal(this.props().enquiry.lines.length > 0 ? 1 : 0);
    protected readonly steps = ['Project', 'Artworks', 'Review & send'];
    protected editing = signal<EnquiryLine | null>(null);
    protected editorOpen = signal(false);
    protected sending = signal(false);

    protected readonly Trash2 = Trash2;
    protected readonly Check = Check;
    protected readonly Plus = Plus;
    protected readonly Pencil = Pencil;
    protected readonly Copy = Copy;
    protected readonly ArrowLeft = ArrowLeft;
    protected readonly ArrowRight = ArrowRight;
    protected readonly Send = Send;
    protected readonly LoaderCircle = LoaderCircle;

    protected breadcrumbs = computed(() => [
        { title: 'My enquiries', href: route('portal.enquiries.index') },
        { title: this.enquiry().display_reference, href: route('portal.enquiries.edit', this.enquiry().id) },
    ]);

    protected summary = computed(() => {
        const e = this.enquiry();
        const rows = [
            { label: 'Project', value: `${e.project_name} · ${e.project_type_label ?? ''}` },
            { label: 'Deliver to', value: e.delivery_address?.one_line ?? 'Not chosen yet' },
            { label: 'Needed by', value: e.required_by ? formatDate(e.required_by) : 'Flexible' },
            { label: 'Budget', value: e.budget_range_label ?? 'Not given' },
            { label: 'Installation', value: e.installation_needed ? 'Yes, please quote it' : 'No' },
        ];
        if (e.notes) rows.push({ label: 'Notes', value: e.notes });
        return rows;
    });

    protected missing = computed(() => {
        const m: string[] = [];
        if (!this.enquiry().delivery_address) m.push('a delivery address (step 1)');
        if (this.enquiry().lines.length === 0) m.push('at least one artwork (step 2)');
        return m;
    });
    protected errorList = computed(() => Object.values(this.props().errors ?? {}));

    protected lineEstimate(id: number) {
        return this.props().estimate.lines[id];
    }

    openEditor(line: EnquiryLine | null): void {
        this.editing.set(line);
        this.editorOpen.set(true);
    }

    duplicate(line: EnquiryLine): void {
        this.router.post(route('portal.enquiries.lines.duplicate', [this.enquiry().id, line.id]), {}, { preserveScroll: true });
    }

    removeLine(line: EnquiryLine): void {
        if (confirm('Remove this artwork?'))
            this.router.delete(route('portal.enquiries.lines.destroy', [this.enquiry().id, line.id]), { preserveScroll: true });
    }

    deleteDraft(): void {
        if (confirm('Delete this draft?')) this.router.delete(route('portal.enquiries.destroy', this.enquiry().id));
    }

    send(): void {
        this.sending.set(true);
        this.router.post(route('portal.enquiries.submit', this.enquiry().id), {}, { onFinish: () => this.sending.set(false) });
    }
}
