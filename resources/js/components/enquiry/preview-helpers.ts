import { cn } from '@/lib/utils';
import type { CatalogFrame, CatalogOptions, EnquiryLine, FrameLayerInput, FrameSplitMode } from '@/types/portal';

export type GlazingLook = 'none' | 'standard' | 'low';

export type FrameProfileShape = 'flat' | 'rounded' | 'scoop' | 'stepped' | 'ornate' | 'floater';

export interface PreviewFrame {
    colour: string;
    width_mm: number;
    depth_mm?: number;
    finish: string;
    /** Moulding cross-section for the 3D preview. */
    profile?: FrameProfileShape;
    /** e.g. "Wood" — wooden mouldings get a subtle grain in 3D. */
    material?: string;
    /**
     * Photo of the moulding face as a horizontal strip, tileable along X: top row = outer edge,
     * bottom row = inner (sight) edge, image height = the face width. When set, the previews wrap
     * it around the artwork with mitred corners instead of drawing the flat colour.
     */
    strip_url?: string | null;
    /** Face width (mm) the strip's height represents (informational; the rails use width_mm). */
    strip_face_mm?: number | null;
}

/**
 * One frame layer for the previews (innermost first). With a split, 'top_bottom_sides' puts the
 * primary moulding on the top & bottom rails and the second on the sides; 'halves' puts the primary
 * on the top half and the second on the bottom half. The layer is as wide as its wider moulding.
 */
export interface PreviewLayer {
    frame: PreviewFrame;
    split?: { frame: PreviewFrame; mode: FrameSplitMode } | null;
}

/** Face width of a layer (mm): the wider of its mouldings. */
export function layerWidth(layer: PreviewLayer): number {
    return Math.max(layer.frame.width_mm, layer.split?.frame.width_mm ?? 0);
}

/** Total face width of all layers (mm); without layers, the single frame's. */
export function framesWidth(frame: PreviewFrame | null, layers?: PreviewLayer[] | null): number {
    return layers?.length ? layers.reduce((sum, l) => sum + layerWidth(l), 0) : (frame?.width_mm ?? 0);
}

/** A catalogue frame as the previews draw it. */
export function previewFrame(frame: CatalogFrame): PreviewFrame {
    return {
        colour: frame.colour,
        width_mm: frame.width_mm,
        depth_mm: frame.depth_mm,
        finish: frame.finish,
        profile: frame.profile ?? (frame.is_floater ? 'floater' : 'flat'),
        material: frame.material,
        strip_url: frame.strip_url ?? null,
        strip_face_mm: frame.strip_face_mm ?? null,
    };
}

/** The line's frame layers (innermost first); a line with only frame_id is one plain layer. */
export function frameLayersOf(ids: { frame_id: number | null; frame_layers?: FrameLayerInput[] | null }): FrameLayerInput[] {
    if (ids.frame_layers?.length) return ids.frame_layers;
    return ids.frame_id ? [{ frame_id: ids.frame_id, split: null }] : [];
}

/** Preview layers for the chosen frame layers (unknown frames are left out). */
export function previewLayers(options: CatalogOptions, layers: FrameLayerInput[]): PreviewLayer[] {
    const find = (id: number) => options.frames.find((f) => f.id === id);
    const out: PreviewLayer[] = [];
    for (const l of layers) {
        const primary = find(l.frame_id);
        if (!primary) continue;
        const second = l.split ? find(l.split.frame_id) : undefined;
        out.push({ frame: previewFrame(primary), split: second && l.split ? { frame: previewFrame(second), mode: l.split.mode } : null });
    }
    return out;
}

export interface PreviewMat {
    colour: string;
    core_colour: string;
    border_mm: number;
    bottom_weight_mm?: number;
}

export const WALL_COLOURS: { name: string; hex: string }[] = [
    { name: 'Warm white', hex: '#EDE8E1' },
    { name: 'Pure white', hex: '#F7F7F5' },
    { name: 'Greige', hex: '#CFC6BA' },
    { name: 'Sage', hex: '#B7C2A8' },
    { name: 'Terracotta', hex: '#C77B5A' },
    { name: 'Dusty pink', hex: '#D9B3AC' },
    { name: 'Navy', hex: '#2E3A55' },
    { name: 'Charcoal', hex: '#3C3C3C' },
];

export const SAMPLE_GRADIENT = 'linear-gradient(135deg,#f6d365 0%,#fda085 35%,#a1c4fd 70%,#5b7db1 100%)';

/**
 * The picture itself (as it hangs) for a saved line: with an "outer" size, what is left inside the frames and mat.
 * Every preview of a saved line draws this size and adds its frame and mat around it.
 */
export function pictureSize(options: CatalogOptions, line: EnquiryLine): { width_mm: number; height_mm: number } {
    const hung = hungSize(line);
    if (line.size_reference !== 'outer') return hung;
    const parts = previewParts(options, line);
    const rebate = parts.layers.length ? (options.rebate_allowance_mm ?? 0) : 0;
    const surround = 2 * (framesWidth(parts.frame, parts.layers) + rebate + (parts.mat?.border_mm ?? 0));
    return {
        width_mm: Math.max(50, hung.width_mm - surround),
        height_mm: Math.max(50, hung.height_mm - surround - (parts.mat?.bottom_weight_mm ?? 0)),
    };
}

/** Frame/mat/depth/glazing for the previews, from the ids chosen on a line. */
/** A saved line's size as it hangs: a catalogue painting turned 90°/270° is saved with its own size, so swap. */
export function hungSize(line: { width_mm: number; height_mm: number; image_rotation?: number; source_type?: string | null }): {
    width_mm: number;
    height_mm: number;
} {
    const turned = line.source_type === 'catalog' && ((line.image_rotation ?? 0) / 90) % 2 === 1;
    return turned ? { width_mm: line.height_mm, height_mm: line.width_mm } : { width_mm: line.width_mm, height_mm: line.height_mm };
}

export function previewParts(
    options: CatalogOptions,
    ids: {
        frame_id: number | null;
        mat_id: number | null;
        mat_border_mm: number | null;
        substrate_id: number | null;
        glazing_id?: number | null;
        frame_layers?: FrameLayerInput[] | null;
    },
): { frame: PreviewFrame | null; layers: PreviewLayer[]; mat: PreviewMat | null; depthMm: number; glazing: GlazingLook } {
    const frame = options.frames.find((f) => f.id === ids.frame_id) ?? null;
    const mat = options.mats.find((m) => m.id === ids.mat_id) ?? null;
    const substrate = options.substrates.find((s) => s.id === ids.substrate_id) ?? null;
    const glazing = options.glazings.find((g) => g.id === ids.glazing_id) ?? null;

    return {
        frame: frame ? previewFrame(frame) : null,
        layers: previewLayers(options, frameLayersOf(ids)),
        mat: mat
            ? {
                  colour: mat.colour,
                  core_colour: mat.core_colour,
                  border_mm: ids.mat_border_mm ?? mat.default_border_mm,
                  bottom_weight_mm: mat.bottom_weight_mm,
              }
            : null,
        // A floater shows the stretched canvas (with its depth) sitting inside the frame.
        depthMm: frame && !frame.is_floater ? 0 : (substrate?.depth_mm ?? 0),
        glazing: !glazing || glazing.material === 'none' ? 'none' : glazing.reflection === 'low' ? 'low' : 'standard',
    };
}

/** Outer size (mm) of the finished piece: picture + mat + rebate gap + frame (all layers, when stacked). */
export function outerSize(
    widthMm: number,
    heightMm: number,
    frame: PreviewFrame | null,
    mat: PreviewMat | null,
    layers?: PreviewLayer[] | null,
    rebateMm = 0,
) {
    const m = mat?.border_mm ?? 0;
    const f = framesWidth(frame, layers) + (frame || layers?.length ? rebateMm : 0);
    return {
        width: widthMm + 2 * m + 2 * f,
        height: heightMm + 2 * m + (mat?.bottom_weight_mm ?? 0) + 2 * f,
    };
}

export function isLight(hex: string): boolean {
    const c = hex.replace('#', '');
    const r = parseInt(c.substring(0, 2), 16);
    const g = parseInt(c.substring(2, 4), 16);
    const b = parseInt(c.substring(4, 6), 16);
    return (r * 299 + g * 587 + b * 114) / 1000 > 140;
}

/** One framed artwork to hang in the 3D room (several pieces = a gallery wall). */
export interface WallPiece {
    id: string | number;
    title?: string;
    imageUrl: string | null;
    /** Larger image (up to 4096 px) for close-up inspection; falls back to imageUrl. */
    detailUrl?: string | null;
    widthMm: number;
    heightMm: number;
    frame: PreviewFrame | null;
    /** Stacked / split frames, innermost first (when set, layers[0] is `frame`). */
    layers?: PreviewLayer[];
    mat: PreviewMat | null;
    depthMm: number;
    edgeStyle: string | null;
    glazing: GlazingLook;
    /** Picture turned clockwise (0/90/180/270°); widthMm/heightMm are already the turned piece's size. */
    rotation?: number;
}

/**
 * The preview stage every tab shares, so switching tabs never jumps: 16:10, never taller than the
 * window leaves room for (dialog header, tabs, footer). With `fill`, on large screens it takes the
 * free height of a flex-column parent instead.
 */
export function stageClass(fill: boolean): string {
    return cn(
        'relative aspect-[16/10] max-h-[calc(100dvh-15rem)] min-h-[320px] w-full',
        fill && 'lg:aspect-auto lg:max-h-none lg:min-h-[240px] lg:flex-1',
    );
}
