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

    protected frameStyle = computed(() => {
        const frame = this.frame();
        const depth = this.depthMm();
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
