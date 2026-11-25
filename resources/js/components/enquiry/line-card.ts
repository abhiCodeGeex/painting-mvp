import type { CatalogOptions, EnquiryLine, LineEstimate } from '@/types/portal';
import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { FramedArt } from './framed-art';
import { hungSize, outerSize, previewParts } from './preview-helpers';
import { LineEstimateTag } from './price-estimate';

/** Compact summary of one artwork line with a small framed thumbnail. Actions go in <ng-content>. */
@Component({
    selector: 'line-card',
    imports: [FramedArt, LineEstimateTag],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex gap-4 rounded-xl border p-4">
            <div class="flex size-32 shrink-0 items-center justify-center rounded-lg" [style.background]="line().wall_colour ?? '#EDE8E1'">
                <framed-art
                    [imageUrl]="line().preview_image"
                    [widthMm]="hung().width_mm"
                    [heightMm]="hung().height_mm"
                    [frame]="parts().frame"
                    [mat]="parts().mat"
                    [depthMm]="parts().depthMm"
                    [rotation]="line().image_rotation ?? 0"
                    [scale]="scale()"
                />
            </div>
            <div class="min-w-0 flex-1">
                <div class="text-muted-foreground text-xs">#{{ index() + 1 }} · {{ line().source_label }}</div>
                <div class="truncate font-medium">{{ line().display_title }}</div>
                @if (line().artwork?.artist) {
                    <div class="text-muted-foreground text-xs">by {{ line().artwork?.artist }}</div>
                }
                <p class="text-muted-foreground mt-2 text-sm leading-relaxed">{{ details() }}</p>
                @if (line().placement) {
                    <p class="text-muted-foreground mt-1 text-xs">For: {{ line().placement }}</p>
                }
                <line-estimate-tag [estimate]="estimate()" />
                <div class="mt-3 flex flex-wrap gap-2 empty:hidden"><ng-content /></div>
            </div>
        </div>
    `,
})
export class LineCard {
    readonly line = input.required<EnquiryLine>();
    readonly options = input.required<CatalogOptions>();
    readonly index = input.required<number>();
    readonly estimate = input<LineEstimate | undefined>(undefined);

    protected parts = computed(() => previewParts(this.options(), this.line()));
    protected hung = computed(() => hungSize(this.line()));
    protected scale = computed(() => {
        const outer = outerSize(this.hung().width_mm, this.hung().height_mm, this.parts().frame, this.parts().mat);
        return 110 / Math.max(outer.width, outer.height);
    });
    protected details = computed(() => {
        const l = this.line();
        return [
            l.size_label + (l.size_reference === 'outer' ? ' (outer)' : ''),
            l.quantity > 1 ? `×${l.quantity}` : null,
            l.labels.medium,
            l.labels.substrate,
            l.labels.frame ? `Frame: ${l.labels.frame}` : l.frame_advice_requested ? 'Frame: advise me' : 'No frame',
            l.labels.mat ? `Mat: ${l.labels.mat}` : null,
            l.labels.glazing,
        ]
            .filter(Boolean)
            .join(' · ');
    });
}
