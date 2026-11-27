import { InertiaForm } from '@/inertia/form';
import { InertiaRouter } from '@/inertia/router';
import { formatDate, formatInr } from '@/lib/units';
import type { CustomerOrder, CustomerPayment, CustomerQuote } from '@/types/portal';
import { DIALOG_DESCRIPTION, DIALOG_HEADER, DIALOG_TITLE, UiDialog } from '@/ui/dialog';
import { FormField } from '@/ui/field';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import { InputError } from '@/ui/text';
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { CircleCheck, LoaderCircle, ShoppingBag, Wallet } from 'lucide';

const TONE: Record<string, string> = {
    success: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    warning: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    danger: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    info: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
    primary: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200',
};

/**
 * "Place order" on an approved quotation: the customer chooses the token to pay now (never less than
 * the minimum, at most the total) and sees the balance due on delivery before committing.
 */
@Component({
    selector: 'place-order',
    imports: [Icon, UI, UiDialog, InputError],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="mt-4 rounded-lg border-2 border-emerald-300 bg-emerald-50 p-4 dark:bg-emerald-950/40" data-test="place-order">
            <div class="font-medium">Ready to go ahead? Place your order.</div>
            <p class="text-muted-foreground mt-0.5 text-sm">
                You pay in two parts: a token amount now (at least <strong class="text-foreground">{{ inr(minimum()) }}</strong>), and the balance
                when your order is delivered.
            </p>
            <button ui-button type="button" class="mt-3 bg-emerald-600 text-white hover:bg-emerald-700" (click)="start()">
                <svg [icon]="ShoppingBag" class="size-4" /> Place order
            </button>
        </div>

        <ui-dialog [(open)]="open" contentClass="sm:max-w-md">
            @if (open()) {
                <div [class]="h">
                    <h2 [class]="t">Place your order</h2>
                    <p [class]="d">Quotation {{ q().number }} · total {{ inr(q().grand_total) }} incl. GST</p>
                </div>
                <div>
                    <label ui-label for="token-amount">How much will you pay now as the token?</label>
                    <div class="relative mt-1">
                        <span class="text-muted-foreground pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm">₹</span>
                        <input
                            ui-input
                            id="token-amount"
                            type="number"
                            inputmode="decimal"
                            class="pl-7 tabular-nums"
                            [min]="minimum() / 100"
                            [max]="q().grand_total / 100"
                            step="1"
                            [value]="rupees()"
                            (input)="rupees.set($any($event.target).value)"
                            data-test="token-amount"
                        />
                    </div>
                    <p class="text-muted-foreground mt-1 text-xs">Minimum {{ inr(minimum()) }} · up to the full {{ inr(q().grand_total) }}.</p>
                    <input-error
                        [message]="
                            tooLow()
                                ? 'The token amount must be at least ' + inr(minimum()) + '.'
                                : tooHigh()
                                  ? 'The token cannot be more than the order total.'
                                  : error()
                        "
                    />
                </div>
                <div class="divide-y rounded-lg border text-sm">
                    <div class="flex items-center justify-between gap-3 p-3">
                        <div>
                            <div class="font-medium">1 · Token amount</div>
                            <div class="text-muted-foreground text-xs">Pay now to confirm your order</div>
                        </div>
                        <div class="font-semibold tabular-nums">{{ inr(token()) }}</div>
                    </div>
                    @if (balance() > 0) {
                        <div class="flex items-center justify-between gap-3 p-3">
                            <div>
                                <div class="font-medium">2 · Full &amp; final</div>
                                <div class="text-muted-foreground text-xs">Pay when your order is delivered</div>
                            </div>
                            <div class="font-semibold tabular-nums">{{ inr(balance()) }}</div>
                        </div>
                    }
                </div>
                <div class="flex justify-end gap-2">
                    <button ui-button variant="outline" type="button" (click)="open.set(false)">Not yet</button>
                    <button
                        ui-button
                        type="button"
                        class="bg-emerald-600 text-white hover:bg-emerald-700"
                        [disabled]="busy() || tooLow() || tooHigh()"
                        (click)="place()"
                    >
                        @if (busy()) {
                            <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                        }
                        Place order · pay {{ inr(token()) }} now
                    </button>
                </div>
            }
        </ui-dialog>
    `,
})
export class PlaceOrder {
    readonly q = input.required<CustomerQuote>({ alias: 'quote' });
    readonly enquiryId = input.required<number>();
    private router = inject(InertiaRouter);
    protected open = signal(false);
    protected busy = signal(false);
    protected error = signal<string | undefined>(undefined);
    /** What the customer typed, in rupees. */
    protected rupees = signal('');
    protected minimum = computed(() => this.q().order_token_amount ?? 0);
    protected token = computed(() => {
        const n = Number(this.rupees());
        return Number.isFinite(n) && this.rupees().trim() !== '' ? Math.round(n * 100) : 0;
    });
    protected tooLow = computed(() => this.token() < this.minimum() || this.rupees().trim() === '');
    protected tooHigh = computed(() => this.token() > this.q().grand_total);
    protected balance = computed(() => Math.max(0, this.q().grand_total - this.token()));
    protected readonly ShoppingBag = ShoppingBag;
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly h = DIALOG_HEADER;
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;
    protected inr = (paise: number) => formatInr(paise, true);

    start(): void {
        this.rupees.set(String(this.minimum() / 100));
        this.error.set(undefined);
        this.open.set(true);
    }

    place(): void {
        if (this.tooLow() || this.tooHigh()) return;
        this.busy.set(true);
        this.error.set(undefined);
        this.router.post(
            route('portal.enquiries.quotes.order', [this.enquiryId(), this.q().id]),
            { token_amount: this.token() / 100 },
            {
                preserveScroll: true,
                onSuccess: () => this.open.set(false),
                onError: (errors: Record<string, string>) => this.error.set(errors['token_amount'] ?? errors['order'] ?? Object.values(errors)[0]),
                onFinish: () => this.busy.set(false),
            },
        );
    }
}

/** An order: its status and the two payments; the customer tells us when they have paid. */
@Component({
    selector: 'order-card',
    imports: [Icon, UI, UiDialog, InputError, FormField],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <section id="order" class="overflow-hidden rounded-xl border-2 border-sky-300 dark:border-sky-800" data-test="order-card">
            <div class="flex flex-wrap items-center justify-between gap-3 bg-sky-50 px-4 py-4 sm:px-5 dark:bg-sky-950/40">
                <div class="flex min-w-0 items-center gap-3">
                    <svg [icon]="ShoppingBag" class="size-6 shrink-0 text-sky-700 dark:text-sky-300" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 font-semibold">
                            Your order {{ o().number }}
                            <span [class]="'rounded-full px-2 py-0.5 text-[11px] font-medium ' + tone(o().status_color)">{{ o().status_label }}</span>
                        </div>
                        <div class="text-muted-foreground text-xs">
                            Placed {{ date(o().placed_at) }} · from quotation {{ o().quote_number }}
                            @if (o().delivered_at) {
                                · delivered {{ date(o().delivered_at!) }}
                            }
                        </div>
                    </div>
                </div>
                @if (o().status !== 'cancelled') {
                    <div class="flex gap-5 text-right">
                        <div>
                            <div class="text-muted-foreground text-xs">Paid</div>
                            <div class="font-semibold tabular-nums">{{ inr(o().paid) }}</div>
                        </div>
                        <div>
                            <div class="text-muted-foreground text-xs">Balance</div>
                            <div class="text-xl font-semibold tabular-nums">{{ inr(o().balance) }}</div>
                        </div>
                    </div>
                }
            </div>

            <div class="divide-y">
                @for (p of o().payments; track p.id; let i = $index) {
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-5" [attr.data-test]="'payment-' + p.kind">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2 text-sm font-medium">
                                {{ i + 1 }} · {{ p.kind === 'token' ? 'Token amount' : 'Full & final' }}
                                <span [class]="'rounded-full px-2 py-0.5 text-[11px] font-medium ' + tone(p.status_color)">{{ p.status_label }}</span>
                            </div>
                            <div class="text-muted-foreground text-xs">
                                @if (o().status === 'cancelled' && p.status !== 'received') {
                                    Not needed — this order is cancelled
                                } @else if (p.reference) {
                                    {{ p.method_label }} · ref. {{ p.reference }}{{ p.paid_on ? ' · ' + date(p.paid_on) : '' }}
                                } @else if (p.kind === 'final' && !p.can_pay && p.status === 'due') {
                                    Due when your order is delivered
                                } @else {
                                    Due now
                                }
                            </div>
                            @if (p.status === 'rejected' && p.note) {
                                <div class="mt-1 text-xs text-red-700 dark:text-red-300">{{ p.note }}</div>
                            }
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="font-semibold tabular-nums">{{ inr(p.amount) }}</span>
                            @if (p.can_pay) {
                                <button ui-button size="sm" type="button" (click)="startPay(p)">
                                    <svg [icon]="Wallet" class="size-4" /> {{ p.status === 'rejected' ? 'Send again' : 'I have paid' }}
                                </button>
                            } @else if (p.status === 'received') {
                                <svg [icon]="CircleCheck" class="size-5 text-emerald-600" />
                            }
                        </div>
                    </div>
                }
            </div>
            <p class="text-muted-foreground border-t px-4 py-3 text-xs sm:px-5">
                Pay by UPI, bank transfer, card, cash or cheque, then tap “I have paid” and add the reference. We confirm every payment here.
            </p>
        </section>

        <ui-dialog [open]="!!paying()" (openChange)="!$event && paying.set(null)" contentClass="sm:max-w-md">
            @if (paying(); as p) {
                <div [class]="h">
                    <h2 [class]="t">{{ p.kind === 'token' ? 'Token amount' : 'Full & final payment' }} · {{ inr(p.amount) }}</h2>
                    <p [class]="d">Tell us how you paid so we can find it. We will confirm it here.</p>
                </div>
                <form class="space-y-3" (submit)="submit($event)">
                    <div>
                        <label ui-label for="pay-method">Paid by</label>
                        <select ui-select id="pay-method" [form]="form" field="method">
                            <option value="">Choose…</option>
                            @for (m of methods(); track m.value) {
                                <option [value]="m.value">{{ m.label }}</option>
                            }
                        </select>
                        <input-error [message]="form.error('method')" />
                    </div>
                    <div>
                        <label ui-label for="pay-ref"
                            >{{ referenceLabel() }}
                            @if (form.data().method === 'cash') {
                                <span class="text-muted-foreground font-normal">(optional)</span>
                            }
                        </label>
                        <input ui-input id="pay-ref" [form]="form" field="reference" />
                        <input-error [message]="form.data().method === 'cash' ? undefined : form.error('reference')" />
                    </div>
                    <div>
                        <label ui-label for="pay-date">Paid on</label>
                        <input ui-input id="pay-date" type="date" [max]="today" [form]="form" field="paid_on" />
                        <input-error [message]="form.error('paid_on')" />
                    </div>
                    <input-error [message]="form.error('payment')" />
                    <div class="flex justify-end gap-2">
                        <button ui-button variant="outline" type="button" (click)="paying.set(null)">Back</button>
                        <button ui-button type="submit" [disabled]="form.processing()">
                            @if (form.processing()) {
                                <svg [icon]="LoaderCircle" class="size-4 animate-spin" />
                            }
                            Send payment details
                        </button>
                    </div>
                </form>
            }
        </ui-dialog>
    `,
})
export class OrderCard {
    readonly o = input.required<CustomerOrder>({ alias: 'order' });
    readonly enquiryId = input.required<number>();
    readonly methods = input<{ value: string; label: string }[]>([]);
    protected paying = signal<CustomerPayment | null>(null);
    protected readonly today = new Date().toISOString().slice(0, 10);
    protected form = new InertiaForm({ method: '', reference: '', paid_on: this.today });
    /** What to ask for depends on how they paid; cash has no transaction number. */
    protected referenceLabel = computed(() => {
        switch (this.form.data().method) {
            case 'cash':
                return 'Receipt number';
            case 'cheque':
                return 'Cheque number';
            case 'upi':
                return 'UPI transaction ID';
            case 'card':
                return 'Card transaction / approval code';
            case 'bank_transfer':
                return 'UTR / transaction number';
            default:
                return 'Transaction / UTR / cheque number';
        }
    });
    protected readonly ShoppingBag = ShoppingBag;
    protected readonly Wallet = Wallet;
    protected readonly CircleCheck = CircleCheck;
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly h = DIALOG_HEADER;
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;
    protected inr = (paise: number) => formatInr(paise, true);
    protected date = formatDate;
    protected tone = (color: string) => TONE[color] ?? 'bg-muted text-muted-foreground';

    startPay(p: CustomerPayment): void {
        this.form.reset();
        this.form.clearErrors();
        this.paying.set(p);
    }

    submit(event: Event): void {
        event.preventDefault();
        const p = this.paying();
        if (!p) return;
        this.form.post(route('portal.enquiries.payments.pay', [this.enquiryId(), p.id]), {
            preserveScroll: true,
            onSuccess: () => this.paying.set(null),
        });
    }
}
