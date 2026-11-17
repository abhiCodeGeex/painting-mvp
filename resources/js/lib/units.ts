// Sizes are stored in millimetres; customers type cm or inches.

export type Unit = 'cm' | 'inch';

export function mmToUnit(mm: number | null | undefined, unit: Unit): string {
    if (mm === null || mm === undefined || Number.isNaN(mm)) return '';
    const value = unit === 'cm' ? mm / 10 : mm / 25.4;
    return String(Math.round(value * 10) / 10);
}

export function unitToMm(value: string | number, unit: Unit): number {
    const n = typeof value === 'number' ? value : parseFloat(String(value).replace(',', '.'));
    if (Number.isNaN(n)) return 0;
    return Math.round(unit === 'cm' ? n * 10 : n * 25.4);
}

export function sizeLabel(widthMm: number, heightMm: number, unit: Unit = 'cm'): string {
    return `${mmToUnit(widthMm, unit)} × ${mmToUnit(heightMm, unit)} ${unit === 'cm' ? 'cm' : 'in'}`;
}

/** Whether a w×h piece fits within limits in either orientation (null = no limit). */
export function fits(w: number, h: number, maxW: number | null, maxH: number | null): boolean {
    if (!maxW && !maxH) return true;
    const long = Math.max(maxW ?? 0, maxH ?? 0);
    const short = maxW && maxH ? Math.min(maxW, maxH) : long;
    return Math.max(w, h) <= long && Math.min(w, h) <= short;
}

export function formatDate(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

/** Paise → "₹1,23,456" (Indian grouping). Paise are shown only when not whole rupees. */
export function formatInr(paise: number | null | undefined, withPaise = false): string {
    const rupees = (paise ?? 0) / 100;
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        minimumFractionDigits: withPaise ? 2 : 0,
        maximumFractionDigits: withPaise ? 2 : 0,
    }).format(rupees);
}

/** "₹12,000" or "₹10,000 – ₹14,000". */
export function formatInrRange(low: number | null | undefined, high: number | null | undefined): string {
    return low === high || high == null ? formatInr(low) : `${formatInr(low)} – ${formatInr(high)}`;
}
