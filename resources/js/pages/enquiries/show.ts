import { GalleryWall } from '@/components/enquiry/gallery-wall';
import { LineCard } from '@/components/enquiry/line-card';
import { pictureSize, previewParts } from '@/components/enquiry/preview-helpers';
import { QuoteResponse } from '@/components/enquiry/quote-response';
import { WallPreview } from '@/components/enquiry/wall-preview';
import { InertiaForm } from '@/inertia/form';
import { InertiaHead } from '@/inertia/head';
import { pageProps } from '@/inertia/page';
import { InertiaRouter } from '@/inertia/router';
import { AppLayout } from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatInr } from '@/lib/units';
import type { CatalogOptions, CustomerQuote, EnquiryDetail, EnquiryLine, EnquiryMessage, RejectionReasonOption } from '@/types/portal';
import { DIALOG_DESCRIPTION, DIALOG_HEADER, DIALOG_TITLE, UiDialog } from '@/ui/dialog';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError, StatusBadge } from '@/ui/text';
import { ChangeDetectionStrategy, Component, computed, inject, input, model, signal } from '@angular/core';
import { Download, Eye, FileText, LayoutGrid, LoaderCircle, Paperclip, Send, X } from 'lucide';

interface Props {
    enquiry: EnquiryDetail;
    messages: EnquiryMessage[];
    options: CatalogOptions;
    quotes: CustomerQuote[];
    rejectionReasons?: RejectionReasonOption[];
}

/* ---------------------------- quote card ---------------------------- */

@Component({
    selector: 'quote-card',
    imports: [Icon, UI, QuoteResponse],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <section id="quote" class="overflow-hidden rounded-xl border-2 border-emerald-300 dark:border-emerald-800">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-emerald-50 px-4 py-4 sm:px-5 dark:bg-emerald-950/40">
                <div class="flex min-w-0 items-center gap-3">
                    <svg [icon]="FileText" class="size-6 shrink-0 text-emerald-700 dark:text-emerald-300" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 font-semibold break-words">
                            Your quotation {{ q().number }}
                            <span [class]="'rounded-full px-2 py-0.5 text-[11px] font-medium ' + badgeClass()">{{ q().status_label }}</span>
                        </div>
                        <div class="text-muted-foreground text-xs">
                            Issued {{ date(q().issue_date) }} · valid until <strong>{{ date(q().valid_until) }}</strong>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-right">
                        <div class="text-muted-foreground text-xs">Total incl. GST</div>
                        <div class="text-xl font-semibold tabular-nums">{{ inr(q().grand_total) }}</div>
                    </div>
                    <a ui-button variant="outline" size="sm" [href]="q().pdf_url" target="_blank" rel="noreferrer"
                        ><svg [icon]="Download" class="size-4" /> PDF</a
                    >
                </div>
            </div>

            <div class="px-4 py-4 sm:px-5">
                <table class="w-full text-sm">
                    <thead class="text-muted-foreground text-left text-xs">
                        <tr>
                            <th class="pb-2 font-normal">Artwork</th>
                            <th class="pb-2 text-right font-normal">Qty</th>
                            <th class="hidden pb-2 text-right font-normal sm:table-cell">Unit price</th>
                            <th class="pb-2 text-right font-normal">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for (l of lines(); track l.id) {
                            <tr class="border-t align-top">
                                <td class="py-2 pr-3">
                                    <div class="font-medium">
                                        {{ l.title }}
                                        @if (l.is_optional) {
                                            <span class="rounded bg-amber-100 px-1.5 text-[10px] text-amber-800">Optional</span>
                                        }
                                    </div>
                                    @if (l.spec) {
                                        <div class="text-muted-foreground text-xs">{{ l.spec }}</div>
                                    }
                                </td>
                                <td class="py-2 pl-2 text-right tabular-nums">{{ l.quantity }}</td>
                                <td class="hidden py-2 text-right tabular-nums sm:table-cell">{{ inr(l.unit_price) }}</td>
                                <td class="py-2 pl-3 text-right whitespace-nowrap tabular-nums">{{ inr(l.line_total) }}</td>
                            </tr>
                        }
                    </tbody>
                </table>

                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div class="text-muted-foreground space-y-1 text-xs">
                        @for (e of q().extras; track e.name) {
                            <div class="flex justify-between gap-2">
                                <span>{{ e.name }}</span>
                                <span>{{ e.inclusion === 'charged' ? inr(e.amount) : e.inclusion_label }}</span>
                            </div>
                        }
                        @if (q().lead_time) {
                            <div class="pt-2">Lead time: {{ q().lead_time }}</div>
                        }
                        @if (q().payment_terms) {
                            <div>Payment: {{ q().payment_terms }}</div>
                        }
                    </div>
                    <div class="space-y-1 text-sm">
                        @for (row of totals(); track row.label) {
                            <div class="flex justify-between gap-2">
                                <span class="text-muted-foreground">{{ row.label }}</span>
                                <span class="tabular-nums">{{ row.value < 0 ? '−' + inr(-row.value) : inr(row.value) }}</span>
                            </div>
                        }
                        <div class="flex justify-between border-t pt-1 font-semibold">
                            <span>Total</span>
                            <span class="tabular-nums">{{ inr(q().grand_total) }}</span>
                        </div>
                    </div>
                </div>

                @if (q().note_to_customer) {
                    <p class="bg-muted mt-4 rounded-lg p-3 text-sm whitespace-pre-line">{{ q().note_to_customer }}</p>
                }
                <quote-response [quote]="q()" [enquiryId]="enquiryId()" [reasons]="reasons()" [roleRequired]="roleRequired()" />
                <p class="text-muted-foreground mt-3 text-xs">Questions about this quotation? Reply in the conversation below.</p>
            </div>
        </section>
    `,
})
export class QuoteCard {
    readonly q = input.required<CustomerQuote>({ alias: 'quote' });
    readonly enquiryId = input.required<number>();
    readonly reasons = input<RejectionReasonOption[]>([]);
    readonly roleRequired = input(false);
    protected badgeClass = computed(() => {
        switch (this.q().status_color) {
            case 'success':
                return 'bg-emerald-600 text-white';
            case 'warning':
                return 'bg-amber-100 text-amber-900';
            case 'danger':
                return 'bg-red-100 text-red-800';
            case 'info':
                return 'bg-sky-100 text-sky-900';
            default:
                return 'bg-muted text-muted-foreground';
        }
    });
    protected readonly FileText = FileText;
    protected readonly Download = Download;
    protected inr = (paise: number) => formatInr(paise, true);
    protected date = formatDate;
    protected lines = computed(() => [...this.q().lines.filter((l) => !l.is_optional), ...this.q().lines.filter((l) => l.is_optional)]);
    protected totals = computed(() => {
        const q = this.q();
        const half = q.tax_rate / 2;
        const rows = [{ label: 'Artworks', value: q.items_total }];
        if (q.extras_total > 0) rows.push({ label: 'Extras', value: q.extras_total });
        if (q.discount_amount > 0) rows.push({ label: 'Discount', value: -q.discount_amount });
        rows.push({ label: 'Taxable value', value: q.taxable });
        if (q.intra_state) {
            rows.push({ label: `CGST ${half}%`, value: q.cgst }, { label: `SGST ${half}%`, value: q.sgst });
        } else {
            rows.push({ label: `IGST ${q.tax_rate}%`, value: q.igst });
        }
        return rows;
    });
}

/* ---------------------------- conversation ---------------------------- */

@Component({
    selector: 'enquiry-conversation',
    imports: [Icon, UI, FormField, InputError],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex flex-col rounded-xl border">
            <div class="max-h-[60vh] space-y-3 overflow-y-auto p-4">
                @for (m of messages(); track m.id) {
                    @if (m.is_system) {
                        <div class="text-muted-foreground text-center text-xs">{{ m.body }} · {{ dateTime(m.created_at) }}</div>
                    } @else {
                        <div [class]="'flex ' + (m.from_staff ? 'justify-start' : 'justify-end')">
                            <div
                                [class]="
                                    'max-w-[85%] rounded-2xl px-4 py-2.5 text-sm ' +
                                    (m.from_staff ? 'bg-muted' : 'bg-primary text-primary-foreground') +
                                    (m.is_question ? ' ring-2 ring-amber-400' : '')
                                "
                            >
                                <div [class]="'mb-1 text-xs ' + (m.from_staff ? 'text-muted-foreground' : 'text-primary-foreground/70')">
                                    {{ m.author }} · {{ dateTime(m.created_at) }}{{ m.is_question ? ' · Question' : '' }}
                                </div>
                                <div class="[overflow-wrap:anywhere] whitespace-pre-line">{{ m.body }}</div>
                                @if (m.attachments.length > 0) {
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        @for (a of m.attachments; track a.id) {
                                            <a [href]="a.url" target="_blank" rel="noreferrer" class="flex items-center gap-1 text-xs underline">
                                                <svg [icon]="Paperclip" class="size-3" /> {{ a.name }}
                                            </a>
                                        }
                                    </div>
                                }
                            </div>
                        </div>
                    }
                }
            </div>

            @if (enquiry().can_message) {
                <form class="space-y-2 border-t p-3" (submit)="submit($event)">
                    <textarea ui-textarea rows="3" placeholder="Write a message…" [form]="form" field="body"></textarea>
                    <input-error [message]="form.error('body')" />
                    @if (form.data().attachments.length > 0) {
                        <div class="flex flex-wrap gap-2">
                            @for (f of form.data().attachments; track $index) {
                                <span class="bg-muted flex items-center gap-1 rounded px-2 py-1 text-xs">
                                    {{ f.name }}
                                    <button type="button" (click)="removeAttachment($index)"><svg [icon]="X" class="size-3" /></button>
                                </span>
                            }
                        </div>
                    }
                    <div class="flex items-center justify-between">
                        <label class="text-muted-foreground hover:text-foreground flex cursor-pointer items-center gap-1 text-sm">
                            <svg [icon]="Paperclip" class="size-4" /> Attach
                            <input type="file" multiple class="hidden" accept="image/*,.pdf,.doc,.docx" (change)="attach($event)" />
                        </label>
                        <button ui-button type="submit" size="sm" [disabled]="form.processing() || !form.data().body.trim()">
                            @if (form.processing()) {
                                <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                            } @else {
                                <svg [icon]="Send" class="size-4" />
                            }
                            Send
                        </button>
                    </div>
                </form>
            } @else {
                <p class="text-muted-foreground border-t p-3 text-center text-xs">This enquiry is closed for messages.</p>
            }
        </div>
    `,
})
export class Conversation {
    readonly enquiry = input.required<EnquiryDetail>();
    readonly messages = input.required<EnquiryMessage[]>();
    protected form = new InertiaForm<{ body: string; attachments: File[] }>({ body: '', attachments: [] });
    protected readonly Paperclip = Paperclip;
    protected readonly Send = Send;
    protected readonly X = X;
    protected readonly LoaderCircle = LoaderCircle;
    protected dateTime = formatDateTime;

    attach(event: Event): void {
        const input = event.target as HTMLInputElement;
        this.form.set('attachments', [...this.form.data().attachments, ...Array.from(input.files ?? [])].slice(0, 10));
        input.value = '';
    }

    removeAttachment(i: number): void {
        this.form.set(
            'attachments',
            this.form.data().attachments.filter((_, j) => j !== i),
        );
    }

    submit(event: Event): void {
        event.preventDefault();
        this.form.post(route('portal.enquiries.messages.store', this.enquiry().id), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => this.form.reset(),
        });
    }
}

/* ---------------------------- cancel dialog ---------------------------- */

@Component({
    selector: 'cancel-dialog',
    imports: [UiDialog, UI],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <ui-dialog [(open)]="open">
            @if (open()) {
                <div [class]="h">
                    <h2 [class]="t">Cancel this enquiry?</h2>
                    <p [class]="d">We will stop working on it. You can always start a new one.</p>
                </div>
                <textarea
                    ui-textarea
                    rows="3"
                    placeholder="Reason (optional)"
                    [value]="reason()"
                    (input)="reason.set($any($event.target).value)"
                ></textarea>
                <div class="flex justify-end gap-2">
                    <button ui-button variant="outline" type="button" (click)="open.set(false)">Keep it</button>
                    <button ui-button variant="destructive" type="button" [disabled]="busy()" (click)="confirm()">Cancel enquiry</button>
                </div>
            }
        </ui-dialog>
    `,
})
export class CancelDialog {
    readonly open = model(false);
    readonly enquiryId = input.required<number>();
    private router = inject(InertiaRouter);
    protected reason = signal('');
    protected busy = signal(false);
    protected readonly h = DIALOG_HEADER;
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;

    confirm(): void {
        this.busy.set(true);
        this.router.post(route('portal.enquiries.cancel', this.enquiryId()), { reason: this.reason() }, { onFinish: () => this.busy.set(false) });
    }
}

/* ---------------------------- the page ---------------------------- */

@Component({
    imports: [InertiaHead, AppLayout, UI, Icon, StatusBadge, LineCard, WallPreview, UiDialog, QuoteCard, Conversation, CancelDialog, GalleryWall],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <app-layout [breadcrumbs]="breadcrumbs()">
            <inertia-head [title]="enquiry().display_reference" />
            <div class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-muted-foreground text-sm">{{ enquiry().display_reference }}</div>
                        <h1 class="text-2xl font-semibold tracking-tight">{{ enquiry().project_name }}</h1>
                        <div class="mt-1 flex items-center gap-2">
                            <status-badge [label]="enquiry().status_label" [color]="enquiry().status_color" />
                            <span class="text-muted-foreground text-xs">Sent {{ date(enquiry().submitted_at) }}</span>
                        </div>
                    </div>
                    @if (enquiry().can_cancel) {
                        <button ui-button variant="ghost" type="button" class="text-muted-foreground" (click)="cancelOpen.set(true)">
                            Cancel enquiry
                        </button>
                    }
                </div>

                @if (enquiry().status === 'needs_clarification') {
                    <a
                        href="#messages"
                        class="block rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        <strong>We have a question for you.</strong> Please reply below so we can prepare your quotation.
                    </a>
                }

                @for (q of currentQuotes(); track q.id) {
                    <quote-card
                        [quote]="q"
                        [enquiryId]="enquiry().id"
                        [reasons]="props().rejectionReasons ?? []"
                        [roleRequired]="enquiry().customer_type === 'company'"
                    />
                }
                @if (olderQuotes().length > 0) {
                    <p class="text-muted-foreground text-xs">
                        Earlier versions:
                        @for (q of olderQuotes(); track q.id) {
                            <a [href]="q.pdf_url" target="_blank" rel="noreferrer" class="mr-2 underline"
                                >{{ q.number }} ({{ q.status_label.toLowerCase() }})</a
                            >
                        }
                    </p>
                }

                <div class="grid gap-6 lg:grid-cols-5">
                    <div class="min-w-0 space-y-3 lg:col-span-3">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-lg font-semibold">Artworks</h2>
                            @if (enquiry().lines.length > 1) {
                                <button ui-button size="sm" variant="outline" type="button" (click)="galleryOpen.set(true)">
                                    <svg [icon]="LayoutGrid" class="size-3.5" /> See all on one wall
                                </button>
                            }
                        </div>
                        @for (line of enquiry().lines; track line.id; let i = $index) {
                            <line-card [line]="line" [options]="props().options" [index]="i">
                                <button ui-button size="sm" variant="outline" type="button" (click)="preview.set(line)">
                                    <svg [icon]="Eye" class="size-3.5" /> See on wall
                                </button>
                            </line-card>
                        }
                        <div class="text-muted-foreground grid gap-1 rounded-xl border p-4 text-sm sm:grid-cols-2">
                            <div>
                                <span class="text-xs">Deliver to</span>
                                <div class="text-foreground">{{ enquiry().delivery_address?.one_line ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-xs">Needed by</span>
                                <div class="text-foreground">{{ enquiry().required_by ? date(enquiry().required_by) : 'Flexible' }}</div>
                            </div>
                        </div>
                    </div>

                    <div id="messages" class="min-w-0 space-y-3 lg:col-span-2">
                        <h2 class="text-lg font-semibold">Conversation</h2>
                        <enquiry-conversation [enquiry]="enquiry()" [messages]="props().messages" />
                    </div>
                </div>
            </div>

            <ui-dialog
                [open]="!!preview()"
                (openChange)="!$event && preview.set(null)"
                contentClass="max-h-[calc(100dvh-2rem)] overflow-y-auto [scrollbar-width:thin] sm:max-w-4xl"
            >
                @if (preview(); as p) {
                    <div [class]="dh">
                        <h2 [class]="dt">{{ p.display_title }}</h2>
                        <p [class]="dd">Preview to scale. Colours and textures are indicative.</p>
                    </div>
                    <wall-preview
                        [imageUrl]="p.preview_image"
                        [detailImageUrl]="p.detail_image ?? null"
                        [fullImageUrl]="p.full_image ?? null"
                        [title]="p.display_title"
                        [widthMm]="pictureSize(props().options, p).width_mm"
                        [heightMm]="pictureSize(props().options, p).height_mm"
                        [frame]="previewParts().frame"
                        [layers]="previewParts().layers"
                        [mat]="previewParts().mat"
                        [depthMm]="previewParts().depthMm"
                        [glazing]="previewParts().glazing"
                        [edgeStyle]="p.edge_style"
                        [rotation]="p.image_rotation ?? 0"
                        [wallColour]="p.wall_colour ?? '#EDE8E1'"
                        [wallWidthMm]="p.wall_width_mm"
                        [wallHeightMm]="p.wall_height_mm"
                        [unit]="p.unit"
                        [quantity]="p.quantity"
                    />
                }
            </ui-dialog>

            <gallery-wall [(open)]="galleryOpen" [lines]="enquiry().lines" [options]="props().options" />

            <cancel-dialog [(open)]="cancelOpen" [enquiryId]="enquiry().id" />
        </app-layout>
    `,
})
export default class ShowEnquiry {
    protected readonly pictureSize = pictureSize;
    protected props = pageProps<Props>();
    protected enquiry = computed(() => this.props().enquiry);
    protected preview = signal<EnquiryLine | null>(null);
    protected cancelOpen = signal(false);
    protected readonly Eye = Eye;
    protected readonly LayoutGrid = LayoutGrid;
    protected galleryOpen = signal(false);
    protected readonly dh = DIALOG_HEADER;
    protected readonly dt = DIALOG_TITLE;
    protected readonly dd = DIALOG_DESCRIPTION;
    protected date = formatDate;

    protected breadcrumbs = computed(() => [
        { title: 'My enquiries', href: route('portal.enquiries.index') },
        { title: this.enquiry().display_reference, href: route('portal.enquiries.show', this.enquiry().id) },
    ]);
    protected currentQuotes = computed(() => (this.props().quotes ?? []).filter((q) => q.is_current));
    protected olderQuotes = computed(() => (this.props().quotes ?? []).filter((q) => !q.is_current));
    protected previewParts = computed(() => {
        const p = this.preview();
        return p ? previewParts(this.props().options, p) : { frame: null, layers: [], mat: null, depthMm: 0, glazing: 'none' as const };
    });
}
