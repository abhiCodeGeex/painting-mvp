import type { CatalogOptions, EnquiryLine } from '@/types/portal';
import type { PreviewFrame, PreviewMat } from './wall-preview';

export type GlazingLook = 'none' | 'standard' | 'low';

/** Frame/mat/depth/glazing for the previews, from the ids chosen on a line. */
export function previewParts(
    options: CatalogOptions,
    ids: {
        frame_id: number | null;
        mat_id: number | null;
        mat_border_mm: number | null;
        substrate_id: number | null;
        glazing_id?: number | null;
    },
): { frame: PreviewFrame | null; mat: PreviewMat | null; depthMm: number; glazing: GlazingLook } {
    const frame = options.frames.find((f) => f.id === ids.frame_id) ?? null;
    const mat = options.mats.find((m) => m.id === ids.mat_id) ?? null;
    const substrate = options.substrates.find((s) => s.id === ids.substrate_id) ?? null;
    const glazing = options.glazings.find((g) => g.id === ids.glazing_id) ?? null;

    return {
        frame: frame ? { colour: frame.colour, width_mm: frame.width_mm, depth_mm: frame.depth_mm, finish: frame.finish } : null,
        mat: mat
            ? {
                  colour: mat.colour,
                  core_colour: mat.core_colour,
                  border_mm: ids.mat_border_mm ?? mat.default_border_mm,
                  bottom_weight_mm: mat.bottom_weight_mm,
              }
            : null,
        depthMm: frame ? 0 : (substrate?.depth_mm ?? 0),
        glazing: !glazing || glazing.material === 'none' ? 'none' : glazing.reflection === 'low' ? 'low' : 'standard',
    };
}

export function linePreviewParts(options: CatalogOptions, line: EnquiryLine) {
    return previewParts(options, line);
}
