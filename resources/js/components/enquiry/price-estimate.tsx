import { formatInr, formatInrRange } from '@/lib/units';
import { cn } from '@/lib/utils';
import type { LineEstimate } from '@/types/portal';
import { ChevronDown, Info, LoaderCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Asks the server for a price whenever the line's options change (debounced).
 * Prices are always calculated on the server so rates and formulas stay private.
 */
export function useLiveEstimate(enquiryId: number, payload: Record<string, unknown>, enabled = true) {
    const [estimate, setEstimate] = useState<LineEstimate | null>(null);
    const [loading, setLoading] = useState(false);
    const key = JSON.stringify(payload);
    const controller = useRef<AbortController | null>(null);

    useEffect(() => {
        if (!enabled) return;
        const timer = window.setTimeout(async () => {
            controller.current?.abort();
            const ctrl = new AbortController();
            controller.current = ctrl;
            setLoading(true);
            try {
                const res = await fetch(route('portal.enquiries.estimate', enquiryId), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                    body: key,
                    signal: ctrl.signal,
                    credentials: 'same-origin',
                });
                // Incomplete input (e.g. size still being typed): keep the last good estimate.
                if (res.ok) setEstimate(await res.json());
            } catch {
                /* aborted or offline: ignore */
            } finally {
                if (controller.current === ctrl) setLoading(false);
            }
        }, 350);
        return () => window.clearTimeout(timer);
    }, [enquiryId, key, enabled]);

    return { estimate, loading };
}

/**
 * "Estimated price" box under the preview in the artwork editor.
 */
export function PriceEstimateBox({ estimate, loading }: { estimate: LineEstimate | null; loading: boolean }) {
    const [open, setOpen] = useState(false);

    if (estimate?.mode === 'hidden') return null;

    const qty = estimate?.quantity ?? 1;
    const ready = estimate?.available;

    return (
        <div className="bg-background rounded-lg border p-3">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
                        Estimated price {loading && <LoaderCircle className="size-3 animate-spin" />}
                    </div>
                    {ready ? (
                        <>
                            <div className="text-lg font-semibold tabular-nums">
                                {formatInrRange(estimate!.unit_low, estimate!.unit_high)}
                                {qty > 1 && <span className="text-muted-foreground text-sm font-normal"> each</span>}
                            </div>
                            {qty > 1 && (
                                <div className="text-sm tabular-nums">
                                    {qty} pieces: <strong>{formatInrRange(estimate!.line_low, estimate!.line_high)}</strong>
                                    {!!estimate!.quantity_discount_percent && (
                                        <span className="ml-1 text-xs text-green-700 dark:text-green-400">incl. {estimate!.quantity_discount_percent}% quantity discount</span>
                                    )}
                                </div>
                            )}
                        </>
                    ) : (
                        <div className="text-muted-foreground text-sm">{loading ? 'Calculating…' : 'Choose an artwork and size to see a price.'}</div>
                    )}
                </div>
                {ready && !!estimate!.items?.length && (
                    <button type="button" onClick={() => setOpen((o) => !o)} className="text-muted-foreground hover:text-foreground flex shrink-0 items-center gap-1 text-xs">
                        Details <ChevronDown className={cn('size-3.5 transition', open && 'rotate-180')} />
                    </button>
                )}
            </div>

            {ready && open && (
                <ul className="mt-2 space-y-1 border-t pt-2 text-xs">
                    {estimate!.items!.map((item) => (
                        <li key={item.label} className="flex justify-between gap-2">
                            <span className="text-muted-foreground truncate">{item.label}</span>
                            <span className="tabular-nums">{formatInr(item.amount)}</span>
                        </li>
                    ))}
                    {!!estimate!.rush_fee_percent && (
                        <li className="flex justify-between gap-2 text-amber-700 dark:text-amber-400">
                            <span>Rush fee (tight deadline)</span>
                            <span>+{estimate!.rush_fee_percent}%</span>
                        </li>
                    )}
                    <li className="text-muted-foreground pt-1">Per piece, before GST{estimate!.is_range ? '; the range allows for final details' : ''}.</li>
                </ul>
            )}

            {ready && !!estimate!.pending?.length && (
                <p className="text-muted-foreground mt-2 text-xs">Not included yet: {estimate!.pending!.join(', ').toLowerCase()}.</p>
            )}

            <p className="text-muted-foreground mt-2 flex items-start gap-1 text-[11px] leading-snug">
                <Info className="mt-px size-3 shrink-0" /> Indicative estimate before GST — the final price is in your quotation.
            </p>
        </div>
    );
}

/** Small price line on an artwork card. */
export function LineEstimateTag({ estimate }: { estimate?: LineEstimate }) {
    if (!estimate || estimate.mode === 'hidden' || !estimate.available) return null;
    return (
        <div className="mt-1 text-sm">
            <span className="text-muted-foreground text-xs">Estimate </span>
            <span className="font-medium tabular-nums">{formatInrRange(estimate.line_low, estimate.line_high)}</span>
            {(estimate.quantity ?? 1) > 1 && <span className="text-muted-foreground text-xs"> for {estimate.quantity}</span>}
        </div>
    );
}
