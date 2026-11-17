import { formatInr, formatInrRange } from '@/lib/units';
import type { LineEstimate } from '@/types/portal';
import { Icon } from '@/ui/icon';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, effect, inject, input, signal, type Signal } from '@angular/core';
import { ChevronDown, Info, LoaderCircle } from 'lucide';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Asks the server for a price whenever the line's options change (debounced).
 * Prices are always calculated on the server so rates and formulas stay private.
 * Call from a component constructor (injection context).
 */
export function liveEstimate(enquiryId: Signal<number>, payload: Signal<Record<string, unknown>>, enabled: Signal<boolean>) {
    const estimate = signal<LineEstimate | null>(null);
    const loading = signal(false);
    let controller: AbortController | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null;

    effect(() => {
        if (!enabled()) return;
        const body = JSON.stringify(payload());
        const id = enquiryId();
        if (timer) clearTimeout(timer);
        timer = setTimeout(async () => {
            controller?.abort();
            const ctrl = new AbortController();
            controller = ctrl;
            loading.set(true);
            try {
                const res = await fetch(route('portal.enquiries.estimate', id), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': xsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body,
                    signal: ctrl.signal,
                    credentials: 'same-origin',
                });
                // Incomplete input (e.g. size still being typed): keep the last good estimate.
                if (res.ok) estimate.set(await res.json());
            } catch {
                /* aborted or offline: ignore */
            } finally {
                if (controller === ctrl) loading.set(false);
            }
        }, 350);
    });

    inject(DestroyRef).onDestroy(() => {
        if (timer) clearTimeout(timer);
        controller?.abort();
    });

    return { estimate, loading };
}

/** "Estimated price" box under the preview in the artwork editor. */
@Component({
    selector: 'price-estimate-box',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (estimate()?.mode !== 'hidden') {
            <div class="bg-background rounded-lg border p-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-muted-foreground flex items-center gap-1.5 text-xs">
                            Estimated price
                            @if (loading()) {
                                <svg [icon]="LoaderCircle" class="size-3 animate-spin" />
                            }
                        </div>
                        @if (e(); as est) {
                            <div class="text-lg font-semibold tabular-nums">
                                {{ range(est.unit_low, est.unit_high) }}
                                @if (qty() > 1) {
                                    <span class="text-muted-foreground text-sm font-normal"> each</span>
                                }
                            </div>
                            @if (qty() > 1) {
                                <div class="text-sm tabular-nums">
                                    {{ qty() }} pieces: <strong>{{ range(est.line_low, est.line_high) }}</strong>
                                    @if (est.quantity_discount_percent) {
                                        <span class="ml-1 text-xs text-green-700 dark:text-green-400"
                                            >incl. {{ est.quantity_discount_percent }}% quantity discount</span
                                        >
                                    }
                                </div>
                            }
                        } @else {
                            <div class="text-muted-foreground text-sm">
                                {{ loading() ? 'Calculating…' : 'Choose an artwork and size to see a price.' }}
                            </div>
                        }
                    </div>
                    @if (e()?.items?.length) {
                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground flex shrink-0 items-center gap-1 text-xs"
                            (click)="open.set(!open())"
                        >
                            Details <svg [icon]="ChevronDown" [class]="'size-3.5 transition' + (open() ? ' rotate-180' : '')" />
                        </button>
                    }
                </div>

                @if (e(); as est) {
                    @if (open()) {
                        <ul class="mt-2 space-y-1 border-t pt-2 text-xs">
                            @for (item of est.items; track item.label) {
                                <li class="flex justify-between gap-2">
                                    <span class="text-muted-foreground truncate">{{ item.label }}</span>
                                    <span class="tabular-nums">{{ inr(item.amount) }}</span>
                                </li>
                            }
                            @if (est.rush_fee_percent) {
                                <li class="flex justify-between gap-2 text-amber-700 dark:text-amber-400">
                                    <span>Rush fee (tight deadline)</span>
                                    <span>+{{ est.rush_fee_percent }}%</span>
                                </li>
                            }
                            <li class="text-muted-foreground pt-1">
                                Per piece, before GST{{ est.is_range ? '; the range allows for final details' : '' }}.
                            </li>
                        </ul>
                    }
                    @if (est.pending?.length) {
                        <p class="text-muted-foreground mt-2 text-xs">Not included yet: {{ pendingText(est) }}.</p>
                    }
                }

                <p class="text-muted-foreground mt-2 flex items-start gap-1 text-[11px] leading-snug">
                    <svg [icon]="Info" class="mt-px size-3 shrink-0" /> Indicative estimate before GST — the final price is in your quotation.
                </p>
            </div>
        }
    `,
})
export class PriceEstimateBox {
    readonly estimate = input<LineEstimate | null>(null);
    readonly loading = input(false);
    protected open = signal(false);
    protected e = computed(() => (this.estimate()?.available ? this.estimate() : null));
    protected qty = computed(() => this.estimate()?.quantity ?? 1);
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly ChevronDown = ChevronDown;
    protected readonly Info = Info;
    protected readonly inr = formatInr;
    protected readonly range = formatInrRange;

    protected pendingText(est: LineEstimate): string {
        return (est.pending ?? []).join(', ').toLowerCase();
    }
}

/** Small price line on an artwork card. */
@Component({
    selector: 'line-estimate-tag',
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (show()) {
            <div class="mt-1 text-sm">
                <span class="text-muted-foreground text-xs">Estimate </span>
                <span class="font-medium tabular-nums">{{ range(estimate()!.line_low, estimate()!.line_high) }}</span>
                @if ((estimate()!.quantity ?? 1) > 1) {
                    <span class="text-muted-foreground text-xs"> for {{ estimate()!.quantity }}</span>
                }
            </div>
        }
    `,
})
export class LineEstimateTag {
    readonly estimate = input<LineEstimate | undefined>(undefined);
    protected show = computed(() => {
        const e = this.estimate();
        return !!e && e.mode !== 'hidden' && !!e.available;
    });
    protected readonly range = formatInrRange;
}
