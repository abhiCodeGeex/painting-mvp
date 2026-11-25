import { mmToUnit, unitToMm, type Unit } from '@/lib/units';
import { cn } from '@/lib/utils';
import { Icon } from '@/ui/icon';
import { UiButton, UiInput } from '@/ui/primitives';
import { ChangeDetectionStrategy, Component, computed, DestroyRef, ElementRef, inject, input, signal, viewChild } from '@angular/core';
import { Camera, Eye, EyeOff, RotateCcw, Upload } from 'lucide';
import { FramedArt } from './framed-art';
import { outerSize, stageClass, type PreviewFrame, type PreviewMat } from './preview-helpers';

type Pt = [number, number];
/** 3×3 homography, row-major [a,b,c, d,e,f, g,h,i]. */
type H = number[];

/** Solves the homography that maps 4 source points onto 4 destination points. */
function homography(src: Pt[], dst: Pt[]): H | null {
    const A: number[][] = [];
    const b: number[] = [];
    for (let i = 0; i < 4; i++) {
        const [x, y] = src[i];
        const [u, v] = dst[i];
        A.push([x, y, 1, 0, 0, 0, -u * x, -u * y]);
        b.push(u);
        A.push([0, 0, 0, x, y, 1, -v * x, -v * y]);
        b.push(v);
    }
    // Gaussian elimination with partial pivoting.
    const n = 8;
    for (let c = 0; c < n; c++) {
        let p = c;
        for (let r = c + 1; r < n; r++) if (Math.abs(A[r][c]) > Math.abs(A[p][c])) p = r;
        if (Math.abs(A[p][c]) < 1e-12) return null;
        [A[c], A[p]] = [A[p], A[c]];
        [b[c], b[p]] = [b[p], b[c]];
        for (let r = c + 1; r < n; r++) {
            const f = A[r][c] / A[c][c];
            for (let k = c; k < n; k++) A[r][k] -= f * A[c][k];
            b[r] -= f * b[c];
        }
    }
    const h = new Array<number>(n).fill(0);
    for (let r = n - 1; r >= 0; r--) {
        let s = b[r];
        for (let k = r + 1; k < n; k++) s -= A[r][k] * h[k];
        h[r] = s / A[r][r];
    }
    return [...h, 1];
}

function apply(m: H, [x, y]: Pt): Pt {
    const w = m[6] * x + m[7] * y + m[8];
    return [(m[0] * x + m[1] * y + m[2]) / w, (m[3] * x + m[4] * y + m[5]) / w];
}

function multiply(a: H, b: H): H {
    const r = new Array<number>(9).fill(0);
    for (let i = 0; i < 3; i++) for (let j = 0; j < 3; j++) for (let k = 0; k < 3; k++) r[i * 3 + j] += a[i * 3 + k] * b[k * 3 + j];
    return r;
}

/** CSS matrix3d (column-major) for a 2D homography. */
function matrix3d(m: H): string {
    const n = m.map((v) => v / m[8]);
    return `matrix3d(${n[0]},${n[3]},0,${n[6]},${n[1]},${n[4]},0,${n[7]},0,0,1,0,${n[2]},${n[5]},0,${n[8]})`;
}

const DEFAULT_CORNERS: Pt[] = [
    [0.3, 0.3],
    [0.7, 0.3],
    [0.7, 0.7],
    [0.3, 0.7],
];

/**
 * "My wall": the customer photographs their wall, drags four dots onto the corners of something
 * measured (a door, a window, a taped rectangle) and the framed artwork is drawn on the photo in
 * perspective, at true size. The photo never leaves the device.
 */
@Component({
    selector: 'wall-photo',
    imports: [Icon, UiButton, UiInput, FramedArt],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        @if (!photo()) {
            <label [class]="emptyClass()">
                <svg [icon]="Camera" class="text-muted-foreground size-8" />
                <span class="font-medium">Upload a photo of your wall</span>
                <span class="text-muted-foreground max-w-sm text-xs">
                    Stand straight in front of the wall. Include something you can measure — a door, a window, or a piece of tape marking a rectangle.
                    Your photo stays on this device; it is not uploaded.
                </span>
                <span ui-button variant="outline" size="sm"><svg [icon]="Upload" class="size-4" /> Choose photo</span>
                <input type="file" accept="image/*" class="hidden" (change)="pick($event)" />
            </label>
        } @else {
            <div class="space-y-2">
                <div #stage class="relative mx-auto w-fit max-w-full touch-none overflow-hidden rounded-lg border bg-black select-none">
                    <img
                        [src]="photo()"
                        alt="Your wall"
                        class="block h-auto max-h-[max(220px,calc(100dvh-29rem))] w-auto max-w-full"
                        draggable="false"
                        (load)="measure()"
                    />

                    @if (transform(); as tf) {
                        <div
                            class="absolute top-0 left-0 origin-top-left cursor-grab active:cursor-grabbing"
                            style="filter: drop-shadow(0 6px 10px rgba(0, 0, 0, 0.35))"
                            [style.transform]="tf"
                            (pointerdown)="grabArt($event)"
                        >
                            <framed-art
                                [imageUrl]="imageUrl()"
                                [widthMm]="widthMm()"
                                [heightMm]="heightMm()"
                                [frame]="frame()"
                                [mat]="mat()"
                                [depthMm]="depthMm()"
                                [rotation]="rotation()"
                                [scale]="1"
                            />
                        </div>
                    }

                    @if (showMarks()) {
                        <svg class="pointer-events-none absolute inset-0 h-full w-full">
                            <polygon
                                [attr.points]="outline()"
                                fill="rgba(245,158,11,0.08)"
                                stroke="#f59e0b"
                                stroke-width="2"
                                stroke-dasharray="6 4"
                            />
                        </svg>
                        @for (c of screenCorners(); track $index) {
                            <span
                                class="absolute size-5 -translate-x-1/2 -translate-y-1/2 cursor-move rounded-full border-2 border-white bg-amber-500 shadow"
                                [style.left.px]="c[0]"
                                [style.top.px]="c[1]"
                                [title]="cornerNames[$index]"
                                (pointerdown)="grabCorner($event, $index)"
                            ></span>
                        }
                    }
                </div>

                <div class="flex flex-wrap items-end gap-3 text-xs">
                    <div class="grid gap-1">
                        <span class="text-muted-foreground">Marked width ({{ unit() === 'cm' ? 'cm' : 'in' }})</span>
                        <input
                            ui-input
                            type="number"
                            min="1"
                            class="h-8 w-24"
                            [value]="refWText()"
                            (input)="setRef('w', $any($event.target).value)"
                        />
                    </div>
                    <div class="grid gap-1">
                        <span class="text-muted-foreground">Marked height ({{ unit() === 'cm' ? 'cm' : 'in' }})</span>
                        <input
                            ui-input
                            type="number"
                            min="1"
                            class="h-8 w-24"
                            [value]="refHText()"
                            (input)="setRef('h', $any($event.target).value)"
                        />
                    </div>
                    <button ui-button variant="ghost" size="sm" type="button" (click)="showMarks.set(!showMarks())">
                        <svg [icon]="showMarks() ? EyeOff : Eye" class="size-4" /> {{ showMarks() ? 'Hide marks' : 'Show marks' }}
                    </button>
                    <button ui-button variant="ghost" size="sm" type="button" (click)="centreArt()">
                        <svg [icon]="RotateCcw" class="size-4" /> Centre artwork
                    </button>
                    <label ui-button variant="outline" size="sm" class="cursor-pointer">
                        <svg [icon]="Upload" class="size-4" /> Other photo
                        <input type="file" accept="image/*" class="hidden" (change)="pick($event)" />
                    </label>
                </div>
                <p class="text-muted-foreground text-xs">
                    Drag the orange dots onto the corners of something you measured and enter its size. Then drag the artwork where you want it.
                </p>
            </div>
        }
    `,
})
export class WallPhoto {
    readonly imageUrl = input<string | null>(null);
    readonly widthMm = input.required<number>();
    readonly heightMm = input.required<number>();
    readonly frame = input<PreviewFrame | null>(null);
    readonly mat = input<PreviewMat | null>(null);
    readonly depthMm = input(0);
    /** Picture turned clockwise (0/90/180/270°); widthMm/heightMm are already the turned piece's size. */
    readonly rotation = input(0);
    readonly unit = input<Unit>('cm');
    /** Take the free height of a flex-column parent on large screens (same stage as the other tabs). */
    readonly fill = input(false);
    protected emptyClass = computed(() =>
        cn(
            stageClass(this.fill()),
            'hover:bg-muted flex cursor-pointer flex-col items-center justify-center gap-3 overflow-hidden rounded-lg border border-dashed p-6 text-center',
        ),
    );

    protected readonly Camera = Camera;
    protected readonly Upload = Upload;
    protected readonly Eye = Eye;
    protected readonly EyeOff = EyeOff;
    protected readonly RotateCcw = RotateCcw;
    protected readonly cornerNames = ['Top left', 'Top right', 'Bottom right', 'Bottom left'];

    protected photo = signal<string | null>(null);
    protected showMarks = signal(true);
    /** Corners of the measured rectangle, as fractions of the photo (TL, TR, BR, BL). */
    private corners = signal<Pt[]>(DEFAULT_CORNERS);
    /** Real size of the measured rectangle (mm). */
    private refW = signal(1000);
    private refH = signal(1000);
    /** Artwork centre on the wall, in mm from the measured rectangle's top-left corner. */
    private artCentre = signal<Pt | null>(null);
    private size = signal<{ w: number; h: number }>({ w: 0, h: 0 });
    private stage = viewChild<ElementRef<HTMLDivElement>>('stage');

    private outer = computed(() => outerSize(this.widthMm(), this.heightMm(), this.frame(), this.mat()));
    protected refWText = computed(() => mmToUnit(this.refW(), this.unit()));
    protected refHText = computed(() => mmToUnit(this.refH(), this.unit()));
    protected screenCorners = computed<Pt[]>(() => this.corners().map(([x, y]) => [x * this.size().w, y * this.size().h]));
    protected outline = computed(() =>
        this.screenCorners()
            .map((c) => c.join(','))
            .join(' '),
    );

    /** Wall (mm, from the rectangle's top-left) → photo pixels. */
    private wallToScreen = computed(() => {
        const w = this.refW();
        const h = this.refH();
        return homography(
            [
                [0, 0],
                [w, 0],
                [w, h],
                [0, h],
            ],
            this.screenCorners(),
        );
    });
    private screenToWall = computed(() => {
        const w = this.refW();
        const h = this.refH();
        return homography(this.screenCorners(), [
            [0, 0],
            [w, 0],
            [w, h],
            [0, h],
        ]);
    });

    /** CSS transform drawing the framed artwork (1 px = 1 mm) onto the wall in perspective. */
    protected transform = computed(() => {
        const m = this.wallToScreen();
        if (!m || !this.size().w) return null;
        const outer = this.outer();
        const [cx, cy] = this.artCentre() ?? [this.refW() / 2, this.refH() / 2];
        const translate: H = [1, 0, cx - outer.width / 2, 0, 1, cy - outer.height / 2, 0, 0, 1];
        return matrix3d(multiply(m, translate));
    });

    private objectUrl: string | null = null;
    private observer = new ResizeObserver(() => this.measure());

    constructor() {
        inject(DestroyRef).onDestroy(() => {
            if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
            this.observer.disconnect();
        });
    }

    pick(event: Event): void {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];
        input.value = '';
        if (!file || !file.type.startsWith('image/')) return;
        if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
        this.objectUrl = URL.createObjectURL(file);
        this.photo.set(this.objectUrl);
        this.corners.set(DEFAULT_CORNERS);
        this.artCentre.set(null);
        this.showMarks.set(true);
    }

    measure(): void {
        const el = this.stage()?.nativeElement;
        if (!el) return;
        this.observer.observe(el);
        const rect = el.getBoundingClientRect();
        this.size.set({ w: rect.width, h: rect.height });
    }

    setRef(which: 'w' | 'h', value: string): void {
        const mm = unitToMm(value, this.unit());
        if (mm > 0) (which === 'w' ? this.refW : this.refH).set(mm);
    }

    centreArt(): void {
        this.artCentre.set(null);
    }

    grabCorner(event: PointerEvent, index: number): void {
        event.preventDefault();
        this.track(event, (x, y) => {
            const { w, h } = this.size();
            const next = [...this.corners()];
            next[index] = [Math.min(1, Math.max(0, x / w)), Math.min(1, Math.max(0, y / h))];
            this.corners.set(next);
        });
    }

    grabArt(event: PointerEvent): void {
        event.preventDefault();
        const toWall = this.screenToWall();
        if (!toWall) return;
        const start = this.localPoint(event);
        const [sx, sy] = apply(toWall, start);
        const [cx, cy] = this.artCentre() ?? [this.refW() / 2, this.refH() / 2];
        this.track(event, (x, y) => {
            const m = this.screenToWall();
            if (!m) return;
            const [wx, wy] = apply(m, [x, y]);
            this.artCentre.set([cx + wx - sx, cy + wy - sy]);
        });
    }

    private localPoint(event: PointerEvent): Pt {
        const rect = this.stage()!.nativeElement.getBoundingClientRect();
        return [event.clientX - rect.left, event.clientY - rect.top];
    }

    /** Follows the pointer until it is released. */
    private track(event: PointerEvent, onMove: (x: number, y: number) => void): void {
        const move = (e: PointerEvent) => {
            const [x, y] = this.localPoint(e);
            onMove(x, y);
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        move(event);
    }
}
