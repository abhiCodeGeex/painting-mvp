import { Icon } from '@/ui/icon';
import {
    afterNextRender,
    ChangeDetectionStrategy,
    Component,
    computed,
    DestroyRef,
    effect,
    ElementRef,
    inject,
    input,
    model,
    signal,
    untracked,
    viewChild,
} from '@angular/core';
import { Expand, LoaderCircle, Minus, Plus, ScanSearch, X } from 'lucide';
import { FramedArt } from './framed-art';
import { outerSize, type PreviewFrame, type PreviewMat } from './preview-helpers';

/**
 * Full-page view of an artwork with its frame and mat: zoom from the whole piece down to the picture's
 * single pixels (the original image, not a preview), drag to move around. Wheel / pinch / double-click /
 * +, −, 0 and Esc keys. The browser draws the transformed picture straight from the full-resolution image,
 * and past 1:1 it shows crisp square pixels instead of blurring them.
 */
@Component({
    selector: 'artwork-lightbox',
    imports: [Icon, FramedArt],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (open()) {
            <div
                class="fixed inset-0 z-[100] flex flex-col bg-neutral-950 text-white select-none"
                role="dialog"
                aria-modal="true"
                [attr.aria-label]="title()"
            >
                <div class="flex items-center gap-3 px-4 py-2.5">
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ title() }}</div>
                        <div class="text-xs text-white/60">
                            {{ sizeLabel() }}
                            @if (natural(); as n) {
                                {{ sizeLabel() ? '·' : '' }} image {{ n.w }} × {{ n.h }} px
                            }
                        </div>
                    </div>
                    @if (loadingFull()) {
                        <span class="flex items-center gap-1 text-xs text-white/70"
                            ><svg [icon]="LoaderCircle" class="size-3.5 animate-spin" /> Loading full resolution…</span
                        >
                    }
                    <div class="flex items-center gap-1 rounded-full bg-white/10 p-1">
                        <button
                            type="button"
                            class="rounded-full p-1.5 hover:bg-white/15"
                            title="Zoom out"
                            aria-label="Zoom out"
                            (click)="zoomBy(1 / 1.6)"
                        >
                            <svg [icon]="Minus" class="size-4" />
                        </button>
                        <span class="w-14 text-center text-xs tabular-nums">{{ percent() }}</span>
                        <button type="button" class="rounded-full p-1.5 hover:bg-white/15" title="Zoom in" aria-label="Zoom in" (click)="zoomBy(1.6)">
                            <svg [icon]="Plus" class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-full p-1.5 hover:bg-white/15"
                            title="Fit to screen"
                            aria-label="Fit to screen"
                            (click)="reset()"
                        >
                            <svg [icon]="Expand" class="size-4" />
                        </button>
                        <button
                            type="button"
                            class="rounded-full p-1.5 hover:bg-white/15"
                            title="Actual pixels (1:1)"
                            aria-label="Actual pixels"
                            (click)="actualPixels()"
                        >
                            <svg [icon]="ScanSearch" class="size-4" />
                        </button>
                    </div>
                    <button
                        type="button"
                        class="rounded-full bg-white/10 p-2 hover:bg-white/20"
                        title="Close (Esc)"
                        aria-label="Close"
                        (click)="open.set(false)"
                    >
                        <svg [icon]="X" class="size-4" />
                    </button>
                </div>

                <div
                    #stage
                    class="relative min-h-0 flex-1 cursor-grab touch-none overflow-hidden active:cursor-grabbing"
                    (wheel)="onWheel($event)"
                    (pointerdown)="onDown($event)"
                    (pointermove)="onMove($event)"
                    (pointerup)="onUp($event)"
                    (pointercancel)="onUp($event)"
                    (dblclick)="onDouble($event)"
                >
                    <div
                        class="absolute top-0 left-0 origin-top-left"
                        [style.transform]="'translate(' + tx() + 'px,' + ty() + 'px) scale(' + scale() + ')'"
                        [style.image-rendering]="pixelated() ? 'pixelated' : 'auto'"
                    >
                        <div [style.padding.px]="pad">
                            <framed-art
                                [imageUrl]="shownUrl()"
                                [widthMm]="widthMm()"
                                [heightMm]="heightMm()"
                                [frame]="frame()"
                                [mat]="mat()"
                                [depthMm]="depthMm()"
                                [rotation]="rotation()"
                                [scale]="mmScale()"
                            />
                        </div>
                    </div>
                    <div
                        class="pointer-events-none absolute bottom-3 left-1/2 -translate-x-1/2 rounded-full bg-black/50 px-3 py-1 text-[11px] text-white/80"
                    >
                        Scroll or pinch to zoom · drag to move · double-click to zoom in
                    </div>
                </div>
            </div>
        }
    `,
})
export class ArtworkLightbox {
    readonly open = model(false);
    readonly title = input('Artwork');
    /** Shown at once (a preview); replaced by fullUrl when it has loaded. */
    readonly previewUrl = input<string | null>(null);
    /** The original image (deep zoom). */
    readonly fullUrl = input<string | null>(null);
    readonly widthMm = input.required<number>();
    readonly heightMm = input.required<number>();
    readonly frame = input<PreviewFrame | null>(null);
    readonly mat = input<PreviewMat | null>(null);
    readonly depthMm = input(0);
    /** Picture turned clockwise (0/90/180/270°); widthMm/heightMm are already the turned piece's size. */
    readonly rotation = input(0);
    readonly sizeLabel = input('');

    protected readonly X = X;
    protected readonly Plus = Plus;
    protected readonly Minus = Minus;
    protected readonly Expand = Expand;
    protected readonly ScanSearch = ScanSearch;
    protected readonly LoaderCircle = LoaderCircle;

    private stage = viewChild<ElementRef<HTMLDivElement>>('stage');
    protected scale = signal(1);
    protected tx = signal(0);
    protected ty = signal(0);
    protected natural = signal<{ w: number; h: number } | null>(null);
    protected loadingFull = signal(false);
    private fullReady = signal(false);
    protected readonly pad = 24;

    /** Base drawing scale (px per mm) so the whole framed piece fits the screen at zoom 1. */
    private viewport = signal({ w: window.innerWidth, h: window.innerHeight - 56 });
    protected mmScale = computed(() => {
        const o = outerSize(this.widthMm(), this.heightMm(), this.frame(), this.mat());
        const v = this.viewport();
        return Math.min((v.w - 2 * this.pad) / o.width, (v.h - 2 * this.pad) / o.height) * 0.92;
    });
    protected shownUrl = computed(() => (this.fullReady() ? this.fullUrl() : this.previewUrl()) ?? this.fullUrl());
    /** Image pixels across the piece's width. */
    private acrossPx = computed(() => {
        const n = this.natural();
        if (!n) return 0;
        // Always upright: a width/height swap only stretches the picture.
        return n.w;
    });
    /** Screen pixels per image pixel at the current zoom. */
    private pixelRatio = computed(() => {
        const across = this.acrossPx();
        if (!across) return 0;
        return (this.widthMm() * this.mmScale() * this.scale()) / across;
    });
    protected pixelated = computed(() => this.pixelRatio() > 2);
    protected percent = computed(() => {
        const r = this.pixelRatio();
        return r ? `${Math.round(r * 100)}%` : `${Math.round(this.scale() * 100)}%`;
    });
    /** Up to 32 screen pixels per image pixel: every single pixel visible. */
    private maxScale = computed(() => {
        const base = (this.widthMm() * this.mmScale()) / (this.acrossPx() || this.widthMm() * this.mmScale());
        return Math.max(4, 32 / base);
    });

    private pointers = new Map<number, { x: number; y: number }>();
    private pinch = 0;

    constructor() {
        const onKey = (e: KeyboardEvent) => {
            if (!this.open()) return;
            if (e.key === 'Escape') {
                // Caught before it reaches the page, so Esc closes only the lightbox, not the dialog under it.
                e.stopPropagation();
                e.preventDefault();
                this.open.set(false);
            } else if (e.key === '+' || e.key === '=') this.zoomBy(1.6);
            else if (e.key === '-') this.zoomBy(1 / 1.6);
            else if (e.key === '0') this.reset();
        };
        const onResize = () => this.viewport.set({ w: window.innerWidth, h: window.innerHeight - 56 });
        window.addEventListener('keydown', onKey, true);
        window.addEventListener('resize', onResize);
        // Live on <body>: opened from inside a dialog, a transformed ancestor would otherwise confine the
        // "fixed" full-page view to that dialog's box.
        const host = inject(ElementRef<HTMLElement>).nativeElement as HTMLElement;
        afterNextRender(() => document.body.appendChild(host));
        inject(DestroyRef).onDestroy(() => {
            window.removeEventListener('keydown', onKey, true);
            window.removeEventListener('resize', onResize);
            host.remove();
        });

        // Opening: fit to screen, then load the original image in the background.
        effect(() => {
            if (!this.open()) return;
            const full = this.fullUrl();
            untracked(() => {
                this.fullReady.set(false);
                this.natural.set(null);
                queueMicrotask(() => this.reset());
                if (!full) return;
                this.loadingFull.set(true);
                const img = new Image();
                img.decoding = 'async';
                img.onload = () => {
                    this.natural.set({ w: img.naturalWidth, h: img.naturalHeight });
                    this.fullReady.set(true);
                    this.loadingFull.set(false);
                };
                img.onerror = () => this.loadingFull.set(false);
                img.src = full;
            });
        });
    }

    /** Whole piece centred on screen. */
    reset(): void {
        const el = this.stage()?.nativeElement;
        const o = outerSize(this.widthMm(), this.heightMm(), this.frame(), this.mat());
        const w = o.width * this.mmScale() + 2 * this.pad;
        const h = o.height * this.mmScale() + 2 * this.pad;
        const sw = el?.clientWidth ?? window.innerWidth;
        const sh = el?.clientHeight ?? window.innerHeight - 56;
        this.scale.set(1);
        this.tx.set((sw - w) / 2);
        this.ty.set((sh - h) / 2);
    }

    /** One image pixel = one screen pixel, around the centre of the screen. */
    actualPixels(): void {
        const across = this.acrossPx();
        if (!across) return;
        const target = across / (this.widthMm() * this.mmScale());
        this.zoomTo(target / this.scale());
    }

    zoomBy(factor: number, at?: { x: number; y: number }): void {
        this.zoomTo(factor, at);
    }

    private zoomTo(factor: number, at?: { x: number; y: number }): void {
        const el = this.stage()?.nativeElement;
        const p = at ?? { x: (el?.clientWidth ?? 0) / 2, y: (el?.clientHeight ?? 0) / 2 };
        const s = this.scale();
        const next = Math.min(this.maxScale(), Math.max(0.5, s * factor));
        const k = next / s;
        // Keep the point under the cursor fixed.
        this.tx.set(p.x - (p.x - this.tx()) * k);
        this.ty.set(p.y - (p.y - this.ty()) * k);
        this.scale.set(next);
    }

    private local(e: { clientX: number; clientY: number }) {
        const r = this.stage()!.nativeElement.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    }

    protected onWheel(e: WheelEvent): void {
        e.preventDefault();
        this.zoomBy(Math.exp(-e.deltaY * 0.0015), this.local(e));
    }

    protected onDouble(e: MouseEvent): void {
        this.zoomBy(2.5, this.local(e));
    }

    protected onDown(e: PointerEvent): void {
        (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
        this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
        if (this.pointers.size === 2) {
            const [a, b] = [...this.pointers.values()];
            this.pinch = Math.hypot(a.x - b.x, a.y - b.y);
        }
    }

    protected onMove(e: PointerEvent): void {
        const prev = this.pointers.get(e.pointerId);
        if (!prev) return;
        const now = { x: e.clientX, y: e.clientY };
        this.pointers.set(e.pointerId, now);
        if (this.pointers.size === 2) {
            const [a, b] = [...this.pointers.values()];
            const d = Math.hypot(a.x - b.x, a.y - b.y);
            if (this.pinch > 0) this.zoomBy(d / this.pinch, this.local({ clientX: (a.x + b.x) / 2, clientY: (a.y + b.y) / 2 }));
            this.pinch = d;
            return;
        }
        this.tx.update((v) => v + now.x - prev.x);
        this.ty.update((v) => v + now.y - prev.y);
    }

    protected onUp(e: PointerEvent): void {
        this.pointers.delete(e.pointerId);
        this.pinch = 0;
    }
}
