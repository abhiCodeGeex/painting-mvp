import type { CatalogOptions } from '@/types/portal';

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
        frame: frame
            ? {
                  colour: frame.colour,
                  width_mm: frame.width_mm,
                  depth_mm: frame.depth_mm,
                  finish: frame.finish,
                  profile: frame.profile ?? (frame.is_floater ? 'floater' : 'flat'),
                  material: frame.material,
                  strip_url: frame.strip_url ?? null,
                  strip_face_mm: frame.strip_face_mm ?? null,
              }
            : null,
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

/** Outer size (mm) of the finished piece: picture + mat + frame. */
export function outerSize(widthMm: number, heightMm: number, frame: PreviewFrame | null, mat: PreviewMat | null) {
    const m = mat?.border_mm ?? 0;
    const f = frame?.width_mm ?? 0;
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
    mat: PreviewMat | null;
    depthMm: number;
    edgeStyle: string | null;
    glazing: GlazingLook;
}
