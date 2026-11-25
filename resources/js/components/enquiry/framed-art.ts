import { Icon } from '@/ui/icon';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { Image as ImageIcon } from 'lucide';
import { layerWidth, SAMPLE_GRADIENT, type PreviewFrame, type PreviewLayer, type PreviewMat } from './preview-helpers';

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

const BEVEL = 'inset 0 0 0 1px rgba(0,0,0,.25), inset 2px 2px 3px rgba(255,255,255,.25), inset -2px -2px 3px rgba(0,0,0,.3)';

type Style = Record<string, string>;
/** Which part of a rail: the whole side, or (split into halves) the upper / lower half of a side rail. */
type Part = 'full' | 'upper' | 'lower';

/**
 * The framed artwork drawn to scale with CSS (flat wall, close-up, thumbnails). Stacked frames are
 * drawn as rings, outermost first, each around the one inside it; the mat sits on top in the middle.
 * A frame with a photo strip is drawn as four mitred rails cut from the strip.
 */
@Component({
    selector: 'framed-art',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'contents' },
    template: `
        <span [style]="frameStyle()">
            @for (ring of rings(); track $index) {
                <span aria-hidden="true" [style]="ring"></span>
            }
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
    /** A single frame (when `layers` is not given). */
    readonly frame = input<PreviewFrame | null>(null);
    /** Stacked / split frames, innermost first. Takes precedence over `frame`. */
    readonly layers = input<PreviewLayer[] | null>(null);
    readonly mat = input<PreviewMat | null>(null);
    readonly depthMm = input(0);
    /**
     * Width and height swapped (any quarter turn): widthMm/heightMm are already the swapped size, and the
     * upright picture is stretched to fill it — never turned or cropped.
     */
    readonly rotation = input(0);
    /** pixels per millimetre */
    readonly scale = input.required<number>();
    protected readonly ImageIcon = ImageIcon;

    private px(mm: number, min = 0): string {
        return `${Math.max(min, mm * this.scale())}px`;
    }

    /** Face width in px (at least 2 px so a thin frame still shows). */
    private faceMin(mm: number): number {
        return Math.max(2, mm * this.scale());
    }

    /** The layers to draw, innermost first. */
    private allLayers = computed<PreviewLayer[]>(() => {
        const layers = this.layers();
        if (layers?.length) return layers;
        const frame = this.frame();
        return frame ? [{ frame, split: null }] : [];
    });

    /** Each layer's face (px) and its outer box (px), from the inside out. */
    private geometry = computed(() => {
        const s = this.scale();
        const mat = this.mat();
        const side = mat ? Math.max(2, mat.border_mm * s) : 0;
        const bottom = mat ? Math.max(2, (mat.border_mm + (mat.bottom_weight_mm ?? 0)) * s) : 0;
        const faces = this.allLayers().map((l) => this.faceMin(layerWidth(l)));
        const total = faces.reduce((a, b) => a + b, 0);
        // Same sizes as the spans below (including their minimums), so rings and rails fit exactly.
        const W = Math.max(4, this.widthMm() * s) + 2 * side + 2 * total;
        const H = Math.max(4, this.heightMm() * s) + side + bottom + 2 * total;
        // Inset of each layer's outer edge from the outside of the piece.
        let outside = total;
        const boxes = faces.map((f) => {
            outside -= f;
            return { f, inset: outside, w: W - 2 * outside, h: H - 2 * outside };
        });
        return { total, boxes };
    });

    /**
     * One box per layer (outermost first, so inner ones paint on top): its face colour, a split drawn
     * with borders (top & bottom / sides meet on the mitres) or a top-half / bottom-half gradient.
     */
    protected rings = computed<Style[]>(() => {
        const layers = this.allLayers();
        const { boxes } = this.geometry();
        return layers
            .map((layer, i) => {
                const { f, inset, w, h } = boxes[i];
                const base: Style = {
                    position: 'absolute',
                    left: `${inset}px`,
                    top: `${inset}px`,
                    width: `${w}px`,
                    height: `${h}px`,
                    'box-sizing': 'border-box',
                    'box-shadow': BEVEL,
                    'pointer-events': 'none',
                };
                const p = layer.frame;
                const split = layer.split;
                if (split?.mode === 'top_bottom_sides') {
                    return {
                        ...base,
                        'border-style': 'solid',
                        'border-width': `${f}px`,
                        'border-color': `${p.colour} ${split.frame.colour} ${p.colour} ${split.frame.colour}`,
                    };
                }
                if (split?.mode === 'halves') {
                    return { ...base, background: `linear-gradient(to bottom, ${p.colour} 50%, ${split.frame.colour} 50%)` };
                }
                return { ...base, background: frameBackground(p.colour, p.finish) };
            })
            .reverse();
    });

    /**
     * Mitred rails cut from the frames' photo strips. Each rail is the strip tiled along its length
     * (background repeat-x, scaled so the strip's height is the face width), rotated 0/90/180/270° so
     * the strip's top row (outer edge) always faces outward, and clipped to a trapezoid so neighbouring
     * rails meet on the 45° diagonals. A split layer takes each rail (or half rail) from its own moulding;
     * a moulding without a strip is drawn in its colour. Background images are re-rasterised when the
     * piece is scaled with a CSS transform, so the frame stays sharp when zoomed.
     */
    protected rails = computed<Style[]>(() => {
        const layers = this.allLayers();
        const { boxes } = this.geometry();
        const out: Style[] = [];
        layers.forEach((layer, i) => {
            const second = layer.split?.frame ?? null;
            if (!layer.frame.strip_url && !second?.strip_url) return;
            const { f, inset, w, h } = boxes[i];
            const mode = layer.split?.mode;
            // Top/bottom rails reach a hair past the mitre so no background seam shows through.
            const o = Math.min(0.6, f / 8);
            const rail = (frame: PreviewFrame, length: number, cx: number, cy: number, angle: number, tint: string, sideRail: boolean, part: Part) => {
                const m = sideRail ? f : f - o;
                // The rail's right end is the top for the left rail (−90°) and the bottom for the right rail (90°).
                const keepRight = part === 'upper' ? angle < 0 : angle > 0;
                const clip =
                    part === 'full'
                        ? `polygon(0 0, 100% 0, calc(100% - ${m}px) 100%, ${m}px 100%)`
                        : keepRight
                          ? `polygon(0 0, 100% 0, calc(100% - ${m}px) 100%, 0 100%)`
                          : `polygon(0 0, 100% 0, 100% 100%, ${m}px 100%)`;
                const face: Style = frame.strip_url
                    ? {
                          'background-image': `linear-gradient(${tint}, ${tint}), url("${frame.strip_url}")`,
                          'background-size': `100% 100%, auto ${f}px`,
                          'background-repeat': 'no-repeat, repeat-x',
                          'background-position': '0 0, center top',
                      }
                    : { background: `linear-gradient(${tint}, ${tint}), ${frameBackground(frame.colour, frame.finish)}` };
                out.push({
                    position: 'absolute',
                    left: `${inset + cx - length / 2}px`,
                    top: `${inset + cy - f / 2}px`,
                    width: `${length}px`,
                    height: `${f}px`,
                    transform: angle ? `rotate(${angle}deg)` : 'none',
                    ...face,
                    'clip-path': clip,
                    'pointer-events': 'none',
                });
            };
            const top = layer.frame;
            const bottom = mode === 'halves' && second ? second : layer.frame;
            const sides = mode === 'top_bottom_sides' && second ? second : layer.frame;
            // Soft light from the top left: top rail a touch brighter, bottom/right a touch darker.
            const L = 'rgba(255,255,255,.03)';
            const R = 'rgba(0,0,0,.07)';
            if (mode === 'halves' && second) {
                // Each side rail in two: the upper half in the top moulding, the lower half in the bottom one.
                rail(top, h / 2, f / 2, h / 4, -90, L, true, 'upper');
                rail(second, h / 2, f / 2, (3 * h) / 4, -90, L, true, 'lower');
                rail(top, h / 2, w - f / 2, h / 4, 90, R, true, 'upper');
                rail(second, h / 2, w - f / 2, (3 * h) / 4, 90, R, true, 'lower');
            } else {
                rail(sides, h, f / 2, h / 2, -90, L, true, 'full'); // left
                rail(sides, h, w - f / 2, h / 2, 90, R, true, 'full'); // right
            }
            rail(top, w, w / 2, f / 2, 0, 'rgba(255,255,255,.06)', false, 'full'); // top
            rail(bottom, w, w / 2, h - f / 2, 180, 'rgba(0,0,0,.12)', false, 'full'); // bottom
        });
        return out;
    });

    protected frameStyle = computed(() => {
        const depth = this.depthMm();
        const layers = this.allLayers();
        if (layers.length) {
            return {
                display: 'inline-block',
                position: 'relative',
                padding: `${this.geometry().total}px`,
                'box-shadow': '0 10px 24px rgba(0,0,0,.35)',
            };
        }
        return {
            display: 'inline-block',
            'box-shadow':
                depth > 0
                    ? `${this.px(depth / 3, 1)} ${this.px(depth / 3, 1)} 0 rgba(0,0,0,.3), 0 10px 22px rgba(0,0,0,.3)`
                    : '0 8px 18px rgba(0,0,0,.25)',
        };
    });

    protected matStyle = computed(() => {
        const mat = this.mat();
        // Positioned, so it paints above the frame rings.
        return mat
            ? {
                  display: 'block',
                  position: 'relative',
                  background: mat.colour,
                  padding: `${this.px(mat.border_mm, 2)} ${this.px(mat.border_mm, 2)} ${this.px(mat.border_mm + (mat.bottom_weight_mm ?? 0), 2)}`,
              }
            : { display: 'block', position: 'relative' };
    });

    /** Clockwise quarter turns, normalised to 0..3. */
    private turns = computed(() => ((Math.round((this.rotation() || 0) / 90) % 4) + 4) % 4);

    protected imageStyle = computed(() => {
        const url = this.imageUrl();
        const mat = this.mat();
        // Rotate only swaps the piece's width and height: the picture stays upright and is stretched to fill.
        const fill = this.turns() > 0 ? '100% 100%' : 'cover';
        return {
            display: 'block',
            width: this.px(this.widthMm(), 4),
            height: this.px(this.heightMm(), 4),
            background: url ? `#ddd url("${url}") center / ${fill} no-repeat` : SAMPLE_GRADIENT,
            'box-shadow': mat ? `0 0 0 ${this.px(2, 1)} ${mat.core_colour}` : null,
        };
    });
}
