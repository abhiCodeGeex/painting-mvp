import { InertiaForm } from '@/inertia/form';
import { formatDateTime, formatInr } from '@/lib/units';
import type { CustomerQuote, RejectionReasonOption } from '@/types/portal';
import { UiCheckbox } from '@/ui/checkbox';
import { DIALOG_DESCRIPTION, DIALOG_HEADER, DIALOG_TITLE, UiDialog } from '@/ui/dialog';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { Check, LoaderCircle, MessageSquareDiff, RefreshCw, X } from 'lucide';

type Mode = 'approve' | 'changes' | 'reject' | 'requote';

/**
 * The customer's answer to a quotation (spec §9): approve (with optional lines and an e-signature),
 * request changes, decline with a reason, or ask for a re-quote once it has expired.
 */
@Component({
    selector: 'quote-response',
    imports: [Icon, UI, UiDialog, UiCheckbox, FormField, InputError],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (q().can_respond) {
            <div class="mt-4 rounded-lg border bg-white/60 p-4 dark:bg-transparent" data-test="quote-response">
                <div class="font-medium">Happy with this quotation?</div>
                <p class="text-muted-foreground mt-0.5 text-xs">
                    Approve it to go ahead, ask us for changes and we will send a revised version, or let us know if it is not for you.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button ui-button type="button" class="bg-emerald-600 text-white hover:bg-emerald-700" (click)="openAs('approve')">
                        <svg [icon]="Check" class="size-4" /> Approve quotation
                    </button>
                    <button ui-button type="button" variant="outline" (click)="openAs('changes')">
                        <svg [icon]="MessageSquareDiff" class="size-4" /> Request changes
                    </button>
                    <button ui-button type="button" variant="ghost" class="text-muted-foreground" (click)="openAs('reject')">
                        <svg [icon]="X" class="size-4" /> Decline
                    </button>
                </div>
            </div>
        } @else if (q().is_expired && q().status !== 'expired') {
            <div class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <strong>This quotation has expired.</strong> Prices may have changed — ask us for an updated quotation.
                <div class="mt-3">
                    <button ui-button type="button" size="sm" variant="outline" (click)="openAs('requote')">
                        <svg [icon]="RefreshCw" class="size-4" /> Request a re-quote
                    </button>
                </div>
            </div>
        } @else if (q().status === 'expired') {
            <div class="text-muted-foreground mt-4 rounded-lg border p-3 text-sm">
                This quotation has expired. You asked for an updated one — we will send it shortly.
            </div>
        } @else if (q().response; as r) {
            <div [class]="'mt-4 rounded-lg border p-3 text-sm ' + responseClass()">
                @switch (r.action) {
                    @case ('approved') {
                        <strong>Approved</strong> by {{ r.name }}{{ r.role ? ', ' + r.role : '' }} on {{ dateTime(q().responded_at) }}
                        @if (r.po_number) {
                            · PO {{ r.po_number }}
                        }
                        <div class="mt-1 text-xs">Thank you! Our team will contact you about the next steps.</div>
                    }
                    @case ('changes_requested') {
                        <strong>You asked for changes</strong> on {{ dateTime(q().responded_at) }}. We are preparing a revised quotation.
                    }
                    @case ('rejected') {
                        <strong>Declined</strong> on {{ dateTime(q().responded_at) }} — {{ r.reason }}
                    }
                }
                @if (r.comment) {
                    <div class="mt-1 text-xs whitespace-pre-line opacity-80">“{{ r.comment }}”</div>
                }
            </div>
        }

        <ui-dialog [open]="!!mode()" (openChange)="!$event && mode.set(null)" contentClass="sm:max-w-lg">
            @switch (mode()) {
                @case ('approve') {
                    <div [class]="h">
                        <h2 [class]="t">Approve quotation {{ q().number }}</h2>
                        <p [class]="d">Your typed name works as your signature.</p>
                    </div>
                    <form class="space-y-3" (submit)="submit($event)">
                        @if (optionalLines().length > 0) {
                            <div class="rounded-lg border p-3">
                                <div class="mb-2 text-xs font-medium">Optional items — tick the ones you want</div>
                                @for (l of optionalLines(); track l.id) {
                                    <label class="flex items-center justify-between gap-3 py-1 text-sm">
                                        <span class="flex items-center gap-2">
                                            <ui-checkbox [checked]="isChosen(l.id)" (checkedChange)="toggle(l.id, $event)" />
                                            {{ l.title }}
                                        </span>
                                        <span class="tabular-nums">{{ inr(l.line_total) }}</span>
                                    </label>
                                }
                            </div>
                        }
                        <div class="flex items-baseline justify-between rounded-lg bg-emerald-50 px-3 py-2 dark:bg-emerald-950/40">
                            <span class="text-sm">Total incl. GST</span>
                            <span class="text-lg font-semibold tabular-nums" data-test="approve-total">{{ inr(total()) }}</span>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label ui-label for="qr-name">Full name</label>
                                <input ui-input id="qr-name" autocomplete="name" [form]="form" field="name" />
                                <input-error [message]="form.error('name')" />
                            </div>
                            <div>
                                <label ui-label for="qr-role"
                                    >Job title / role
                                    <span class="text-muted-foreground font-normal">{{ roleRequired() ? '' : '(optional)' }}</span></label
                                >
                                <input ui-input id="qr-role" autocomplete="organization-title" [form]="form" field="role" />
                                <input-error [message]="form.error('role')" />
                            </div>
                        </div>
                        <div>
                            <label ui-label for="qr-po">PO number <span class="text-muted-foreground font-normal">(optional)</span></label>
                            <input ui-input id="qr-po" [form]="form" field="po_number" />
                            <input-error [message]="form.error('po_number')" />
                        </div>
                        <div>
                            <label ui-label for="qr-note">Note for us <span class="text-muted-foreground font-normal">(optional)</span></label>
                            <textarea ui-textarea id="qr-note" rows="2" [form]="form" field="comment"></textarea>
                        </div>
                        <label class="flex items-start gap-2 text-sm">
                            <ui-checkbox [checked]="form.data().accept_terms" (checkedChange)="form.set('accept_terms', $event)" />
                            <span>I accept the quotation's terms &amp; conditions and confirm this order.</span>
                        </label>
                        <input-error [message]="form.error('accept_terms')" />
                        <input-error [message]="form.error('status')" />
                        <div class="flex justify-end gap-2 pt-1">
                            <button ui-button variant="outline" type="button" (click)="mode.set(null)">Not yet</button>
                            <button ui-button type="submit" class="bg-emerald-600 text-white hover:bg-emerald-700" [disabled]="form.processing()">
                                @if (form.processing()) {
                                    <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                                }
                                Approve {{ inr(total()) }}
                            </button>
                        </div>
                    </form>
                }
                @case ('changes') {
                    <div [class]="h">
                        <h2 [class]="t">Request changes</h2>
                        <p [class]="d">Tell us what to change — size, frame, quantity, price, delivery… We will send you a revised quotation.</p>
                    </div>
                    <form class="space-y-3" (submit)="submit($event)">
                        <textarea ui-textarea rows="5" placeholder="What would you like us to change?" [form]="form" field="comment"></textarea>
                        <input-error [message]="form.error('comment')" />
                        <input-error [message]="form.error('status')" />
                        <div class="flex justify-end gap-2">
                            <button ui-button variant="outline" type="button" (click)="mode.set(null)">Back</button>
                            <button ui-button type="submit" [disabled]="form.processing() || !form.data().comment.trim()">Send request</button>
                        </div>
                    </form>
                }
                @case ('reject') {
                    <div [class]="h">
                        <h2 [class]="t">Decline this quotation?</h2>
                        <p [class]="d">Your feedback helps us. If it is only about the price or details, “Request changes” may be better.</p>
                    </div>
                    <form class="space-y-3" (submit)="submit($event)">
                        <div>
                            <label ui-label for="qr-reason">Reason</label>
                            <select ui-select id="qr-reason" [form]="form" field="rejection_reason_id">
                                <option value="">Choose a reason…</option>
                                @for (r of reasons(); track r.id) {
                                    <option [value]="r.id">{{ r.name }}</option>
                                }
                            </select>
                            <input-error [message]="form.error('rejection_reason_id')" />
                        </div>
                        <div>
                            <label ui-label for="qr-comment"
                                >Comment <span class="text-muted-foreground font-normal">{{ commentRequired() ? '' : '(optional)' }}</span></label
                            >
                            <textarea ui-textarea id="qr-comment" rows="3" [form]="form" field="comment"></textarea>
                            <input-error [message]="form.error('comment')" />
                        </div>
                        <input-error [message]="form.error('status')" />
                        <div class="flex justify-end gap-2">
                            <button ui-button variant="outline" type="button" (click)="mode.set(null)">Back</button>
                            <button ui-button variant="destructive" type="submit" [disabled]="form.processing() || !form.data().rejection_reason_id">
                                Decline quotation
                            </button>
                        </div>
                    </form>
                }
                @case ('requote') {
                    <div [class]="h">
                        <h2 [class]="t">Request an updated quotation</h2>
                        <p [class]="d">We will review current prices and send you a new version.</p>
                    </div>
                    <form class="space-y-3" (submit)="submit($event)">
                        <textarea ui-textarea rows="3" placeholder="Anything we should know? (optional)" [form]="form" field="comment"></textarea>
                        <input-error [message]="form.error('status')" />
                        <div class="flex justify-end gap-2">
                            <button ui-button variant="outline" type="button" (click)="mode.set(null)">Back</button>
                            <button ui-button type="submit" [disabled]="form.processing()">Request re-quote</button>
                        </div>
                    </form>
                }
            }
        </ui-dialog>
    `,
})
export class QuoteResponse {
    readonly q = input.required<CustomerQuote>({ alias: 'quote' });
    readonly enquiryId = input.required<number>();
    readonly reasons = input<RejectionReasonOption[]>([]);
    readonly roleRequired = input(false);

    protected mode = signal<Mode | null>(null);
    protected chosen = signal<Set<number>>(new Set());
    protected form = new InertiaForm({
        name: '',
        role: '',
        po_number: '',
        comment: '',
        accept_terms: false,
        rejection_reason_id: '',
        optional_line_ids: [] as number[],
    });

    protected readonly Check = Check;
    protected readonly X = X;
    protected readonly MessageSquareDiff = MessageSquareDiff;
    protected readonly RefreshCw = RefreshCw;
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly h = DIALOG_HEADER;
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;
    protected inr = (paise: number) => formatInr(paise, true);
    protected dateTime = (iso: string | null) => (iso ? formatDateTime(iso) : '');

    protected optionalLines = computed(() => this.q().lines.filter((l) => l.is_optional));
    protected commentRequired = computed(
        () => this.reasons().find((r) => String(r.id) === String(this.form.data().rejection_reason_id))?.requires_comment ?? false,
    );
    protected responseClass = computed(() => {
        switch (this.q().response?.action) {
            case 'approved':
                return 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200';
            case 'rejected':
                return 'text-muted-foreground';
            default:
                return 'border-amber-300 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200';
        }
    });

    /** Same arithmetic as QuoteBuilder::recalculate() + GstCalculator, so the total shown is the total approved. */
    protected total = computed(() => {
        const q = this.q();
        const chosen = this.chosen();
        const items = q.lines.filter((l) => !l.is_optional || chosen.has(l.id)).reduce((sum, l) => sum + l.line_total, 0);
        const base = items + q.extras_total;
        const discount = q.discount_percent > 0 ? Math.round((base * q.discount_percent) / 100) : Math.min(q.discount_amount, base);
        const taxable = base - discount;
        return taxable + Math.round((taxable * q.tax_rate) / 100);
    });

    isChosen(id: number): boolean {
        return this.chosen().has(id);
    }

    toggle(id: number, on: boolean): void {
        const next = new Set(this.chosen());
        if (on) next.add(id);
        else next.delete(id);
        this.chosen.set(next);
    }

    openAs(mode: Mode): void {
        this.form.reset();
        this.form.clearErrors();
        this.chosen.set(
            new Set(
                this.optionalLines()
                    .filter((l) => l.is_selected)
                    .map((l) => l.id),
            ),
        );
        this.mode.set(mode);
    }

    submit(event: Event): void {
        event.preventDefault();
        const mode = this.mode();
        if (!mode) return;
        const name = { approve: 'approve', changes: 'changes', reject: 'reject', requote: 'requote' }[mode];
        this.form
            .transform((data) => (mode === 'approve' ? { ...data, optional_line_ids: [...this.chosen()] } : data))
            .post(route(`portal.enquiries.quotes.${name}`, [this.enquiryId(), this.q().id]), {
                preserveScroll: true,
                onSuccess: () => this.mode.set(null),
            });
    }
}
