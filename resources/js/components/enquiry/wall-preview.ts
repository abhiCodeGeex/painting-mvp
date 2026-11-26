import { mmToUnit, sizeLabel, type Unit } from '@/lib/units';
import { cn } from '@/lib/utils';
import { Icon } from '@/ui/icon';
import {
    afterNextRender,
    ChangeDetectionStrategy,
    Component,
    computed,
    DestroyRef,
    ElementRef,
    inject,
    input,
    linkedSignal,
    output,
    signal,
    viewChild,
} from '@angular/core';
import { LoaderCircle, Maximize2 } from 'lucide';
import { ArtworkLightbox } from './artwork-lightbox';
import { FramedArt } from './framed-art';
import {
    isLight,
    outerSize,
    stageClass,
    WALL_COLOURS,
    type GlazingLook,
    type PreviewFrame,
    type PreviewLayer,
    type PreviewMat,
    type WallPiece,
} from './preview-helpers';
import { Room360 } from './room-360';
import { WallPhoto } from './wall-photo';

type View = '360' | 'photo' | 'room' | 'closeup';

const VIEW_LABELS: Record<View, string> = { '360': 'Room 360°', photo: 'My wall', room: 'Flat wall', closeup: 'Close-up' };
const SOFA_WIDTH = 2000;
const SOFA_HEIGHT = 850;

function supportsWebGL(): boolean {
    try {
        const canvas = document.createElement('canvas');
        return !!(canvas.getContext('webgl2') || canvas.getContext('webgl'));
    } catch {
        return false;
    }
}

/**
 * True-to-scale preview: an interactive 3D room (loaded on demand), a flat wall above a
 * 2 m sofa, and a close-up of the framing.
 */
@Component({
    selector: 'wall-preview',
    imports: [Icon, FramedArt, Room360, WallPhoto, ArtworkLightbox],
    changeDetection: ChangeDetectionStrategy.OnPush,
    // The host is the flex item of the parent; with fillHeight it passes the free height on to the root.
    // Its minimum height is its content's (the stage's minimum), so it never overlaps what follows it.
    host: { '[class]': "fillHeight() ? 'flex min-w-0 flex-col lg:flex-1' : 'block min-w-0'" },
    template: `
        <div [class]="rootClass()">
            <div
                role="tablist"
                aria-label="Preview view"
                class="bg-muted grid w-full auto-cols-fr grid-flow-col gap-1 rounded-lg p-1 sm:inline-grid sm:w-auto sm:self-start"
            >
                @for (v of views; track v) {
                    <button
                        type="button"
                        role="tab"
                        [attr.aria-selected]="view() === v"
                        [class]="
                            'rounded-md px-3 py-1.5 text-xs font-medium whitespace-nowrap transition sm:text-sm ' +
                            (view() === v ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')
                        "
                        (click)="view.set(v)"
                    >
                        {{ labels[v] }}
                    </button>
                }
            </div>

            @if (view() === '360') {
                <!-- 16:10 stage (or the free height, with fillHeight), never shorter than 360 px; the room fills it. -->
                <div [class]="stageClass()">
                    @defer (on immediate) {
                        <room-360
                            class="absolute inset-0 block"
                            height="h-full"
                            [pieces]="pieces()"
                            [wallColour]="wall()"
                            (wallChange)="setWall($event)"
                        />
                    } @placeholder {
                        <div class="text-muted-foreground absolute inset-0 flex items-center justify-center gap-2 rounded-lg border text-sm">
                            <svg [icon]="LoaderCircle" class="size-4 animate-spin" /> Preparing the 360° room…
                        </div>
                    } @error {
                        <div
                            class="text-muted-foreground absolute inset-0 flex items-center justify-center rounded-lg border p-6 text-center text-sm"
                        >
                            Couldn’t load the 360° room — please reload the page.
                        </div>
                    }
                </div>
            }

            <!-- Kept once opened (hidden on other tabs), so the wall photo and its measurements survive a tab switch. -->
            @if (photoOpened()) {
                <!-- The wrapper hides the loading / error stages on other tabs too. -->
                <div [class]="photoClass()" [class.hidden]="view() !== 'photo'">
                @defer (on immediate) {
                    <wall-photo
                        [class]="photoClass()"
                        [fill]="fillHeight()"
                        [imageUrl]="imageUrl()"
                        [widthMm]="w()"
                        [heightMm]="h()"
                        [frame]="frame()"
                        [layers]="layers()"
                        [mat]="mat()"
                        [depthMm]="depthMm()"
                        [rotation]="rotation()"
                        [unit]="unit()"
                    />
                } @placeholder {
                    <div [class]="stageClass()">
                        <div class="text-muted-foreground absolute inset-0 flex items-center justify-center gap-2 rounded-lg border text-sm">
                            <svg [icon]="LoaderCircle" class="size-4 animate-spin" /> Loading…
                        </div>
                    </div>
                } @error {
                    <div [class]="stageClass()">
                        <div
                            class="text-muted-foreground absolute inset-0 flex items-center justify-center rounded-lg border p-6 text-center text-sm"
                        >
                            Couldn’t load this view — please reload the page.
                        </div>
                    </div>
                }
                </div>
            }

            <!-- Same stage as the 360° room, so switching tabs never jumps; the drawing is fitted inside it. -->
            <div #box [class]="stageClass() + ' overflow-hidden rounded-lg border' + (view() === '360' || view() === 'photo' ? ' hidden' : '')">
                @if (view() === 'room') {
                    <div class="absolute inset-0" [style.background]="wall()">
                        <!-- floor -->
                        <div
                            class="absolute inset-x-0 bottom-0"
                            [style.height.px]="floorMm * scale()"
                            style="background: linear-gradient(#a98463, #8a6a4f); border-top: 3px solid rgba(0, 0, 0, 0.12)"
                        ></div>
                        <!-- the customer's own wall, when they gave its size -->
                        @if (myWall(); as mw) {
                            <div
                                class="pointer-events-none absolute left-1/2 -translate-x-1/2 border-2 border-dashed"
                                [class]="lightWall() ? 'border-black/35' : 'border-white/50'"
                                [style.bottom.px]="floorMm * scale()"
                                [style.width.px]="mw.width * scale()"
                                [style.height.px]="mw.height * scale()"
                            >
                                <span
                                    [class]="'absolute top-1 left-1/2 -translate-x-1/2 text-[10px] whitespace-nowrap ' + (lightWall() ? 'text-black/55' : 'text-white/70')"
                                    >Your wall · {{ myWallLabel() }}</span
                                >
                            </div>
                        }
                        <!-- sofa for scale -->
                        <svg
                            [class]="'absolute left-1/2 -translate-x-1/2' + (showSofa() ? '' : ' hidden')"
                            [style.bottom.px]="floorMm * scale() * 0.55"
                            [style.width.px]="sofaWidth * scale()"
                            [style.height.px]="sofaHeight * scale()"
                            viewBox="0 0 200 85"
                            preserveAspectRatio="none"
                            aria-hidden="true"
                        >
                            <rect x="0" y="22" width="200" height="50" rx="8" [attr.fill]="lightWall() ? '#6b7280' : '#9ca3af'" opacity=".55" />
                            <rect x="8" y="5" width="184" height="35" rx="8" [attr.fill]="lightWall() ? '#4b5563' : '#d1d5db'" opacity=".5" />
                            <rect x="10" y="72" width="6" height="13" fill="#374151" opacity=".6" />
                            <rect x="184" y="72" width="6" height="13" fill="#374151" opacity=".6" />
                        </svg>
                        <!-- artwork -->
                        <div class="absolute left-1/2 flex -translate-x-1/2 flex-col items-center" [style.bottom.px]="bottomPx()">
                            <framed-art
                                [imageUrl]="imageUrl()"
                                [widthMm]="w()"
                                [heightMm]="h()"
                                [frame]="frame()"
                                [layers]="layers()"
                                [mat]="mat()"
                                [depthMm]="depthMm()"
                                [rotation]="rotation()"
                                [scale]="scale()"
                            />
                        </div>
                        <span [class]="'absolute top-2 left-2 text-[10px] ' + (lightWall() ? 'text-black/50' : 'text-white/60')"
                            >{{ showSofa() ? 'Sofa 2 m wide for scale' : 'Drawn to scale' }}</span
                        >
                    </div>
                } @else if (view() === 'closeup') {
                    <div class="absolute inset-0 flex items-center justify-center" [style.background]="wall()">
                        <framed-art
                            [imageUrl]="imageUrl()"
                            [widthMm]="w()"
                            [heightMm]="h()"
                            [frame]="frame()"
                            [layers]="layers()"
                            [mat]="mat()"
                            [depthMm]="depthMm()"
                            [rotation]="rotation()"
                            [scale]="closeScale()"
                        />
                    </div>
                }
                <!-- Wall colour: the same swatches, in the same corner, as in the 360° room. -->
                <div class="absolute bottom-2 left-2 flex items-center gap-1.5 rounded-full bg-black/45 px-2 py-1.5 backdrop-blur-sm">
                    @for (c of colours; track c.hex) {
                        <button
                            type="button"
                            [attr.aria-label]="'Wall colour ' + c.name"
                            [attr.aria-pressed]="wall().toLowerCase() === c.hex.toLowerCase()"
                            [title]="c.name"
                            [class]="
                                'size-5 rounded-full border transition ' +
                                (wall().toLowerCase() === c.hex.toLowerCase() ? 'scale-110 border-white ring-2 ring-white/70' : 'border-white/40')
                            "
                            [style.background]="c.hex"
                            (click)="setWall(c.hex)"
                        ></button>
                    }
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-muted-foreground text-xs">
                    Finished size ≈ <span class="text-foreground font-medium tabular-nums">{{ finishedLabel() }}</span>{{
                        quantity() > 1 ? ' · ×' + quantity() : ''
                    }}
                    @if (fit(); as f) {
                        <span [class]="f.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'"> · {{ f.text }}</span>
                    }
                </p>
                @if (imageUrl()) {
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs font-medium"
                        (click)="fullOpen.set(true)"
                    >
                        <svg [icon]="Maximize2" class="size-3.5" /> View full size
                    </button>
                }
            </div>
        </div>
        <artwork-lightbox
            [(open)]="fullOpen"
            [title]="title() || 'Your artwork'"
            [previewUrl]="imageUrl()"
            [fullUrl]="fullImageUrl() || detailImageUrl() || imageUrl()"
            [widthMm]="w()"
            [heightMm]="h()"
            [frame]="frame()"
            [layers]="layers()"
            [mat]="mat()"
            [depthMm]="depthMm()"
            [rotation]="rotation()"
            [sizeLabel]="'Finished size ≈ ' + finishedLabel()"
        />
    `,
})
export class WallPreview {
    readonly imageUrl = input<string | null>(null);
    readonly detailImageUrl = input<string | null>(null);
    /** The original picture, for the full-page viewer (pixel-level zoom). */
    readonly fullImageUrl = input<string | null>(null);
    readonly title = input('');
    protected fullOpen = signal(false);
    protected readonly Maximize2 = Maximize2;
    readonly widthMm = input.required<number>();
    readonly heightMm = input.required<number>();
    readonly frame = input<PreviewFrame | null>(null);
    /** Stacked / split frames, innermost first (optional; layers[0] is `frame`). */
    readonly layers = input<PreviewLayer[] | null>(null);
    /** Rebate gap (mm, each side) inside a frame: part of the finished size, as priced. */
    readonly rebateMm = input(0);
    readonly mat = input<PreviewMat | null>(null);
    readonly depthMm = input(0);
    readonly edgeStyle = input<string | null>(null);
    /** Picture turned clockwise (0/90/180/270°); widthMm/heightMm are already the turned piece's size. */
    readonly rotation = input(0);
    readonly glazing = input<GlazingLook>('none');
    readonly wallColour = input.required<string>();
    /** The wall colour picked on the preview's swatches (any tab): the page saves it with the artwork. */
    readonly wallColourChange = output<string>();
    /** The colour shown on every tab: follows the page, and a swatch tap changes it at once. */
    protected wall = linkedSignal(() => this.wallColour() || '#EDE8E1');
    protected readonly colours = WALL_COLOURS;
    protected setWall(hex: string): void {
        this.wall.set(hex);
        this.wallColourChange.emit(hex);
    }
    readonly wallWidthMm = input<number | null>(null);
    readonly wallHeightMm = input<number | null>(null);
    readonly unit = input<Unit>('cm');
    readonly quantity = input(1);
    readonly userClass = input('', { alias: 'class' });
    /** On large screens, let the 360° room take the free height of a flex-column parent instead of a 16:10 box. */
    readonly fillHeight = input(false);

    private readonly webgl = supportsWebGL();
    protected readonly views: View[] = this.webgl ? ['360', 'photo', 'room', 'closeup'] : ['photo', 'room', 'closeup'];
    protected readonly labels = VIEW_LABELS;
    protected view = signal<View>(this.webgl ? '360' : 'room');
    /** "My wall" stays alive after it is first opened. */
    protected photoOpened = linkedSignal<View, boolean>({ source: this.view, computation: (v, prev) => v === 'photo' || !!prev?.value });
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly floorMm = 250;
    protected readonly sofaWidth = SOFA_WIDTH;
    protected readonly sofaHeight = SOFA_HEIGHT;

    private box = viewChild<ElementRef<HTMLDivElement>>('box');
    private containerWidth = signal(480);
    private containerHeight = signal(300);

    protected rootClass = computed(() => cn('flex min-w-0 flex-col gap-3', this.fillHeight() && 'lg:flex-1', this.userClass()));
    protected stageClass = computed(() => stageClass(this.fillHeight()));
    protected photoClass = computed(() => cn('flex min-w-0 flex-col', this.fillHeight() && 'lg:flex-1'));
    protected w = computed(() => Math.max(50, this.widthMm() || 0));
    protected h = computed(() => Math.max(50, this.heightMm() || 0));
    /** The artwork as a one-piece list for the 3D room. */
    // Same piece (e.g. only notes typed): same array, so the 3D room doesn't rebuild its meshes.
    protected pieces = computed<WallPiece[]>(
        () => [
        {
            id: 'piece',
            imageUrl: this.imageUrl(),
            detailUrl: this.detailImageUrl(),
            widthMm: this.w(),
            heightMm: this.h(),
            frame: this.frame(),
            layers: this.layers() ?? undefined,
            mat: this.mat(),
            depthMm: this.depthMm(),
            edgeStyle: this.edgeStyle(),
            glazing: this.glazing(),
            rotation: this.rotation(),
        },
    ],
        { equal: (a, b) => JSON.stringify(a) === JSON.stringify(b) },
    );
    private outer = computed(() => outerSize(this.w(), this.h(), this.frame(), this.mat(), this.layers(), this.rebateMm()));
    protected finishedLabel = computed(() => sizeLabel(this.outer().width, this.outer().height, this.unit()));

    /** The customer's wall (when they typed its width): drawn as a dashed outline with the piece on it. */
    protected myWall = computed(() => {
        const width = this.wallWidthMm() || 0;
        if (width < 100) return null;
        return { width, height: this.wallHeightMm() && this.wallHeightMm()! >= 100 ? this.wallHeightMm()! : 2600 };
    });
    protected myWallLabel = computed(() => {
        const mw = this.myWall();
        if (!mw) return '';
        return this.wallHeightMm() ? sizeLabel(mw.width, mw.height, this.unit()) : `${this.len(mw.width)} wide`;
    });
    private len(mm: number): string {
        return `${mmToUnit(mm, this.unit())} ${this.unit() === 'cm' ? 'cm' : 'in'}`;
    }
    /** The sofa only when it fits the customer's wall (or no wall size was given). */
    protected showSofa = computed(() => !this.myWall() || this.myWall()!.width >= SOFA_WIDTH + 200);
    /** Does the framed piece fit the customer's wall? Shown next to the finished size. */
    protected fit = computed<{ ok: boolean; text: string } | null>(() => {
        const mw = this.myWall();
        if (!mw) return null;
        const o = this.outer();
        if (o.width > mw.width) return { ok: false, text: 'wider than your wall' };
        if (this.wallHeightMm() && o.height > mw.height - 300) return { ok: false, text: 'too tall for your wall' };
        const side = (mw.width - o.width) / 2;
        if (side < 150) return { ok: false, text: 'a very tight fit on your wall' };
        return { ok: true, text: `fits your wall, ${this.len(side)} each side` };
    });
    // Wall is at least the given size, and always wide/tall enough to show the piece comfortably.
    private wallW = computed(() => {
        const mw = this.myWall();
        if (mw) return Math.max(mw.width + 600, this.outer().width + 600, this.showSofa() ? SOFA_WIDTH + 600 : 0);
        return Math.max(3200, this.outer().width + 800, SOFA_WIDTH + 600);
    });
    protected wallH = computed(() => {
        const mw = this.myWall();
        if (mw) return Math.max(mw.height + 200, this.outer().height + 600);
        return Math.max(this.wallHeightMm() || 2600, this.outer().height + SOFA_HEIGHT + 900);
    });
    // Fit the whole wall (floor to ceiling, sofa included) inside the box; the wall colour fills the rest.
    protected scale = computed(() => Math.min(this.containerWidth() / this.wallW(), this.containerHeight() / (this.wallH() + this.floorMm)));
    // Hang with the centre at ~1500 mm, but keep at least 200 mm above the sofa back.
    protected bottomPx = computed(() => {
        const mw = this.myWall();
        let centre = this.showSofa() ? Math.max(1500, SOFA_HEIGHT + 200 + this.outer().height / 2) : Math.max(1500, this.outer().height / 2 + 300);
        // Never hang above the top of the customer's wall.
        if (mw) centre = Math.max(this.outer().height / 2 + 100, Math.min(centre, mw.height - this.outer().height / 2 - 100));
        return (centre - this.outer().height / 2 + this.floorMm) * this.scale();
    });
    protected lightWall = computed(() => isLight(this.wall()));
    // Close-up: the whole framed piece fitted to the box with a 24 px margin (plus room for the shadow).
    protected closeScale = computed(() =>
        Math.min(
            (this.containerWidth() - 48) / this.outer().width,
            (this.containerHeight() - 64) / this.outer().height,
            // Never blow a tiny piece up past ~4 px per mm.
            4,
        ),
    );

    constructor() {
        const destroyRef = inject(DestroyRef);
        afterNextRender(() => {
            const el = this.box()?.nativeElement;
            if (!el) return;
            const observer = new ResizeObserver(([entry]) => {
                // A hidden box (3D view) reports 0: keep the last real size.
                const { width, height } = entry.contentRect;
                if (width > 0 && height > 0) {
                    this.containerWidth.set(Math.max(240, width));
                    this.containerHeight.set(Math.max(200, height));
                }
            });
            observer.observe(el);
            destroyRef.onDestroy(() => observer.disconnect());
        });
    }
}
