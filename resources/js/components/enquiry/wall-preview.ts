import { sizeLabel, type Unit } from '@/lib/units';
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
    signal,
    viewChild,
} from '@angular/core';
import { LoaderCircle } from 'lucide';
import { FramedArt } from './framed-art';
import { isLight, outerSize, type GlazingLook, type PreviewFrame, type PreviewMat, type WallPiece } from './preview-helpers';
import { Room360 } from './room-360';
import { Room3D } from './room-3d';
import { WallPhoto } from './wall-photo';

type View = '360' | '3d' | 'photo' | 'room' | 'closeup';

const VIEW_LABELS: Record<View, string> = { '360': '360° room', '3d': '3D room', photo: 'My wall', room: 'Flat wall', closeup: 'Close-up' };
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
    imports: [Icon, FramedArt, Room360, Room3D, WallPhoto],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div [class]="rootClass()">
            <div class="flex items-center justify-between text-xs">
                <div class="bg-muted inline-flex rounded-md p-0.5">
                    @for (v of views; track v) {
                        <button
                            type="button"
                            [class]="
                                'rounded px-2.5 py-1 font-medium transition ' + (view() === v ? 'bg-background shadow-sm' : 'text-muted-foreground')
                            "
                            (click)="view.set(v)"
                        >
                            {{ labels[v] }}
                        </button>
                    }
                </div>
                <span class="text-muted-foreground">Finished size ≈ {{ finishedLabel() }}{{ quantity() > 1 ? ' · ×' + quantity() : '' }}</span>
            </div>

            @if (view() === '360') {
                @defer (on immediate) {
                    <room-360 [pieces]="pieces()" [wallColour]="wallColour()" />
                } @placeholder {
                    <div class="text-muted-foreground flex h-[420px] items-center justify-center gap-2 rounded-lg border text-sm">
                        <svg [icon]="LoaderCircle" class="size-4 animate-spin" /> Preparing the 360° room…
                    </div>
                }
            }

            @if (view() === '3d') {
                @defer (on immediate) {
                    <room-3d [pieces]="pieces()" [wallColour]="wallColour()" [wallWidthMm]="wallWidthMm()" [wallHeightMm]="wallHeightMm()" />
                } @loading (minimum 100ms) {
                    <div class="text-muted-foreground flex h-[360px] items-center justify-center gap-2 rounded-lg border text-sm">
                        <svg [icon]="LoaderCircle" class="size-4 animate-spin" /> Preparing the 3D room…
                    </div>
                } @placeholder {
                    <div class="text-muted-foreground flex h-[360px] items-center justify-center gap-2 rounded-lg border text-sm">
                        <svg [icon]="LoaderCircle" class="size-4 animate-spin" /> Preparing the 3D room…
                    </div>
                }
            }

            @if (view() === 'photo') {
                @defer (on immediate) {
                    <wall-photo
                        [imageUrl]="imageUrl()"
                        [widthMm]="w()"
                        [heightMm]="h()"
                        [frame]="frame()"
                        [mat]="mat()"
                        [depthMm]="depthMm()"
                        [unit]="unit()"
                    />
                }
            }

            <div
                #box
                [class]="'w-full overflow-hidden rounded-lg border' + (view() === '360' || view() === '3d' || view() === 'photo' ? ' hidden' : '')"
            >
                @if (view() === 'room') {
                    <div class="relative" [style.height.px]="(wallH() + floorMm) * scale()" [style.background]="wallColour()">
                        <!-- floor -->
                        <div
                            class="absolute inset-x-0 bottom-0"
                            [style.height.px]="floorMm * scale()"
                            style="background: linear-gradient(#a98463, #8a6a4f); border-top: 3px solid rgba(0, 0, 0, 0.12)"
                        ></div>
                        <!-- sofa for scale -->
                        <svg
                            class="absolute left-1/2 -translate-x-1/2"
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
                                [mat]="mat()"
                                [depthMm]="depthMm()"
                                [scale]="scale()"
                            />
                        </div>
                        <span [class]="'absolute top-2 left-2 text-[10px] ' + (lightWall() ? 'text-black/50' : 'text-white/60')"
                            >Sofa 2 m wide for scale</span
                        >
                    </div>
                } @else if (view() === 'closeup') {
                    <div class="flex items-center justify-center p-6" [style.background]="wallColour()" [style.min-height.px]="closeBox() + 48">
                        <framed-art
                            [imageUrl]="imageUrl()"
                            [widthMm]="w()"
                            [heightMm]="h()"
                            [frame]="frame()"
                            [mat]="mat()"
                            [depthMm]="depthMm()"
                            [scale]="closeScale()"
                        />
                    </div>
                }
            </div>
        </div>
    `,
})
export class WallPreview {
    readonly imageUrl = input<string | null>(null);
    readonly widthMm = input.required<number>();
    readonly heightMm = input.required<number>();
    readonly frame = input<PreviewFrame | null>(null);
    readonly mat = input<PreviewMat | null>(null);
    readonly depthMm = input(0);
    readonly edgeStyle = input<string | null>(null);
    readonly glazing = input<GlazingLook>('none');
    readonly wallColour = input.required<string>();
    readonly wallWidthMm = input<number | null>(null);
    readonly wallHeightMm = input<number | null>(null);
    readonly unit = input<Unit>('cm');
    readonly quantity = input(1);
    readonly userClass = input('', { alias: 'class' });

    private readonly webgl = supportsWebGL();
    protected readonly views: View[] = this.webgl ? ['360', '3d', 'photo', 'room', 'closeup'] : ['photo', 'room', 'closeup'];
    protected readonly labels = VIEW_LABELS;
    protected view = signal<View>(this.webgl ? '360' : 'room');
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly floorMm = 250;
    protected readonly sofaWidth = SOFA_WIDTH;
    protected readonly sofaHeight = SOFA_HEIGHT;

    private box = viewChild<ElementRef<HTMLDivElement>>('box');
    private containerWidth = signal(480);

    protected rootClass = computed(() => cn('flex flex-col gap-2', this.userClass()));
    protected w = computed(() => Math.max(50, this.widthMm() || 0));
    protected h = computed(() => Math.max(50, this.heightMm() || 0));
    /** The artwork as a one-piece list for the 3D room. */
    protected pieces = computed<WallPiece[]>(() => [
        {
            id: 'piece',
            imageUrl: this.imageUrl(),
            widthMm: this.w(),
            heightMm: this.h(),
            frame: this.frame(),
            mat: this.mat(),
            depthMm: this.depthMm(),
            edgeStyle: this.edgeStyle(),
            glazing: this.glazing(),
        },
    ]);
    private outer = computed(() => outerSize(this.w(), this.h(), this.frame(), this.mat()));
    protected finishedLabel = computed(() => sizeLabel(this.outer().width, this.outer().height, this.unit()));

    // Wall is at least the given size, and always wide/tall enough to show the piece comfortably.
    private wallW = computed(() => Math.max(this.wallWidthMm() || 3200, this.outer().width + 800, SOFA_WIDTH + 600));
    protected wallH = computed(() => Math.max(this.wallHeightMm() || 2600, this.outer().height + SOFA_HEIGHT + 900));
    protected scale = computed(() => this.containerWidth() / this.wallW());
    // Hang with the centre at ~1500 mm, but keep at least 200 mm above the sofa back.
    protected bottomPx = computed(() => {
        const centre = Math.max(1500, SOFA_HEIGHT + 200 + this.outer().height / 2);
        return (centre - this.outer().height / 2 + this.floorMm) * this.scale();
    });
    protected lightWall = computed(() => isLight(this.wallColour()));
    protected closeBox = computed(() => Math.min(this.containerWidth() - 48, 420));
    protected closeScale = computed(() => this.closeBox() / Math.max(this.outer().width, this.outer().height));

    constructor() {
        const destroyRef = inject(DestroyRef);
        afterNextRender(() => {
            const el = this.box()?.nativeElement;
            if (!el) return;
            const observer = new ResizeObserver(([entry]) => {
                // A hidden box (3D view) reports 0: keep the last real width.
                if (entry.contentRect.width > 0) this.containerWidth.set(Math.max(240, entry.contentRect.width));
            });
            observer.observe(el);
            destroyRef.onDestroy(() => observer.disconnect());
        });
    }
}
