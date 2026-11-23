import { Icon } from '@/ui/icon';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { Image as ImageIcon } from 'lucide';
import { SAMPLE_GRADIENT, type PreviewFrame, type PreviewMat } from './preview-helpers';

function frameBackground(colour: string, finish: string): string {
    switch (finish) {
        case 'gloss':
            return `linear-gradient(135deg, rgba(255,255,255,.35) 0%, rgba(255,255,255,0) 40%), ${colour}`;
        case 'metallic':
            return `linear-gradient(135deg, rgba(255,255,255,.55) 0%, rgba(255,255,255,.05) 30%, rgba(0,0,0,.15) 55%, rgba(255,255,255,.35) 80%), ${colour}`;
        case 'satin':
            return `linear-gradient(135deg, rgba(255,255,255,.15) 0%, rgba(255,255,255,0) 50%), ${colour}`;
        default:
            return colour;
    }
}

/** The framed artwork drawn to scale with CSS (flat wall, close-up, thumbnails). */
@Component({
    selector: 'framed-art',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    template: `
        <span [style]="frameStyle()">
            @for (rail of rails(); track $index) {
                <span aria-hidden="true" [style]="rail"></span>
            }
            <span [style]="matStyle()">
                <span [style]="imageStyle()" class="relative">
                    @if (!imageUrl()) {
                        <span class="absolute inset-0 flex items-center justify-center text-white/80">
                            <svg [icon]="ImageIcon" class="size-1/4 max-h-10 max-w-10" />
                        </span>
                    }
                </span>
            </span>
        </span>
    `,
})
export class FramedArt {
    readonly imageUrl = input<string | null>(null);
    readonly widthMm = input.required<number>();
    readonly heightMm = input.required<number>();
    readonly frame = input<PreviewFrame | null>(null);
    readonly mat = input<PreviewMat | null>(null);
    readonly depthMm = input(0);
    /** pixels per millimetre */
    readonly scale = input.required<number>();
    protected readonly ImageIcon = ImageIcon;

    private px(mm: number, min = 0): string {
        return `${Math.max(min, mm * this.scale())}px`;
    }

    /** Face width of the frame in px (the frame span's padding). */
    private faceMin(mm: number): number {
        return Math.max(2, mm * this.scale());
    }

    /**
     * The four rails cut from the frame's photo strip, like a real mitred frame. Each rail is the
     * strip tiled along its length (background repeat-x, scaled so the strip's height is the face
     * width), rotated 0/90/180/270° so the strip's top row (outer edge) always faces outward, and
     * clipped to a trapezoid so neighbouring rails meet on the 45° diagonals. Background images are
     * re-rasterised from the source when the whole piece is scaled with a CSS transform, so the
     * frame stays as sharp as the strip photo when zoomed.
     */
    protected rails = computed<Record<string, string>[]>(() => {
        const frame = this.frame();
        const url = frame?.strip_url;
        if (!frame || !url) return [];
        const s = this.scale();
        const mat = this.mat();
        const f = this.faceMin(frame.width_mm);
        // Same sizes as the spans below (including their minimums), so the rails fit exactly.
        const side = mat ? Math.max(2, mat.border_mm * s) : 0;
        const bottom = mat ? Math.max(2, (mat.border_mm + (mat.bottom_weight_mm ?? 0)) * s) : 0;
        const w = Math.max(4, this.widthMm() * s) + 2 * side + 2 * f;
        const h = Math.max(4, this.heightMm() * s) + side + bottom + 2 * f;
        // Top/bottom rails reach a hair past the mitre so no background seam shows through.
        const o = Math.min(0.6, f / 8);
        const clip = `polygon(0 0, 100% 0, calc(100% - ${f - o}px) 100%, ${f - o}px 100%)`;
        const clipSide = `polygon(0 0, 100% 0, calc(100% - ${f}px) 100%, ${f}px 100%)`;
        const rail = (length: number, left: number, top: number, angle: number, tint: string, sideRail: boolean) => ({
            position: 'absolute',
            left: `${left}px`,
            top: `${top}px`,
            width: `${length}px`,
            height: `${f}px`,
            transform: angle ? `rotate(${angle}deg)` : 'none',
            'background-image': `linear-gradient(${tint}, ${tint}), url("${url}")`,
            'background-size': `100% 100%, auto ${f}px`,
            'background-repeat': 'no-repeat, repeat-x',
            'background-position': '0 0, center top',
            'clip-path': sideRail ? clipSide : clip,
            'pointer-events': 'none',
        });
        // Soft light from the top left: top rail a touch brighter, bottom/right a touch darker.
        return [
            rail(h, f / 2 - h / 2, h / 2 - f / 2, -90, 'rgba(255,255,255,.03)', true), // left
            rail(h, w - f / 2 - h / 2, h / 2 - f / 2, 90, 'rgba(0,0,0,.07)', true), // right
            rail(w, 0, 0, 0, 'rgba(255,255,255,.06)', false), // top
            rail(w, 0, h - f, 180, 'rgba(0,0,0,.12)', false), // bottom
        ];
    });

    protected frameStyle = computed(() => {
        const frame = this.frame();
        const depth = this.depthMm();
        if (frame?.strip_url) {
            return {
                display: 'inline-block',
                position: 'relative',
                padding: `${this.faceMin(frame.width_mm)}px`,
                // Shows only if a mitre seam ever opens up, or while the strip is loading.
                background: frame.colour,
                'box-shadow': '0 10px 24px rgba(0,0,0,.35)',
            };
        }
        return frame
            ? {
                  display: 'inline-block',
                  padding: this.px(frame.width_mm, 2),
                  background: frameBackground(frame.colour, frame.finish),
                  'box-shadow':
                      'inset 0 0 0 1px rgba(0,0,0,.25), inset 2px 2px 3px rgba(255,255,255,.25), inset -2px -2px 3px rgba(0,0,0,.3), 0 10px 24px rgba(0,0,0,.35)',
              }
            : {
                  display: 'inline-block',
                  'box-shadow':
                      depth > 0
                          ? `${this.px(depth / 3, 1)} ${this.px(depth / 3, 1)} 0 rgba(0,0,0,.3), 0 10px 22px rgba(0,0,0,.3)`
                          : '0 8px 18px rgba(0,0,0,.25)',
              };
    });

    protected matStyle = computed(() => {
        const mat = this.mat();
        return mat
            ? {
                  display: 'block',
                  background: mat.colour,
                  padding: `${this.px(mat.border_mm, 2)} ${this.px(mat.border_mm, 2)} ${this.px(mat.border_mm + (mat.bottom_weight_mm ?? 0), 2)}`,
              }
            : { display: 'block' };
    });

    protected imageStyle = computed(() => {
        const url = this.imageUrl();
        const mat = this.mat();
        return {
            display: 'block',
            width: this.px(this.widthMm(), 4),
            height: this.px(this.heightMm(), 4),
            background: url ? `#ddd url("${url}") center / cover no-repeat` : SAMPLE_GRADIENT,
            'box-shadow': mat ? `0 0 0 ${this.px(2, 1)} ${mat.core_colour}` : null,
        };
    });
}
