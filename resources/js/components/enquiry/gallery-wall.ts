import type { CatalogOptions, EnquiryLine } from '@/types/portal';
import { DIALOG_DESCRIPTION, DIALOG_HEADER, DIALOG_TITLE, UiDialog } from '@/ui/dialog';
import { Icon } from '@/ui/icon';
import { ChangeDetectionStrategy, Component, computed, input, model } from '@angular/core';
import { LoaderCircle } from 'lucide';
import { previewParts, type WallPiece } from './preview-helpers';
import { Room3D } from './room-3d';

/** The artwork itself (picture size) for a line, whatever size reference the customer used. */
export function lineToPiece(options: CatalogOptions, line: EnquiryLine): WallPiece {
    const parts = previewParts(options, line);
    const surround = 2 * ((parts.frame?.width_mm ?? 0) + (parts.mat?.border_mm ?? 0));
    const outer = line.size_reference === 'outer';
    return {
        id: line.id,
        title: line.display_title,
        imageUrl: line.preview_image,
        widthMm: outer ? Math.max(50, line.width_mm - surround) : line.width_mm,
        heightMm: outer ? Math.max(50, line.height_mm - surround - (parts.mat?.bottom_weight_mm ?? 0)) : line.height_mm,
        frame: parts.frame,
        mat: parts.mat,
        depthMm: parts.depthMm,
        edgeStyle: line.edge_style,
        glazing: parts.glazing,
    };
}

/**
 * All artworks of an enquiry together on one wall: drag them around, with snapping
 * alignment guides, in any room style.
 */
@Component({
    selector: 'gallery-wall',
    imports: [UiDialog, Room3D, Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <ui-dialog [(open)]="open" contentClass="sm:max-w-5xl">
            @if (open()) {
                <div [class]="h">
                    <h2 [class]="t">All artworks on one wall</h2>
                    <p [class]="d">
                        {{ lines().length }} artworks to scale. Drag them to arrange your wall — they snap to each other's edges and centres. Colours and textures are indicative.
                    </p>
                </div>
                @defer (on immediate) {
                    <room-3d [pieces]="pieces()" [wallColour]="wallColour()" height="h-[460px]" />
                } @placeholder {
                    <div class="text-muted-foreground flex h-[460px] items-center justify-center gap-2 rounded-lg border text-sm">
                        <svg [icon]="LoaderCircle" class="size-4 animate-spin" /> Preparing the 3D room…
                    </div>
                }
            }
        </ui-dialog>
    `,
})
export class GalleryWall {
    readonly open = model(false);
    readonly lines = input.required<EnquiryLine[]>();
    readonly options = input.required<CatalogOptions>();

    protected pieces = computed(() => this.lines().map((line) => lineToPiece(this.options(), line)));
    /** The wall colour most lines asked for (falls back to warm white). */
    protected wallColour = computed(() => {
        const counts = new Map<string, number>();
        for (const l of this.lines()) if (l.wall_colour) counts.set(l.wall_colour, (counts.get(l.wall_colour) ?? 0) + 1);
        return [...counts.entries()].sort((a, b) => b[1] - a[1])[0]?.[0] ?? '#EDE8E1';
    });
    protected readonly LoaderCircle = LoaderCircle;
    protected readonly h = DIALOG_HEADER;
    protected readonly t = DIALOG_TITLE;
    protected readonly d = DIALOG_DESCRIPTION;
}
