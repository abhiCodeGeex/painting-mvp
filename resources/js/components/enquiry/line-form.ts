import type { Unit } from '@/lib/units';
import type { CatalogOptions, EnquiryLine, LineSourceType } from '@/types/portal';
import { WALL_COLOURS } from './preview-helpers';

export type LineForm = {
    source_type: LineSourceType;
    artwork_id: number | null;
    title: string;
    brief: { description: string; style: string; mood: string[]; palette: string[]; usage_rights: string };
    references: File[];
    remove_media: number[];
    own: { service: string; condition_notes: string };
    artwork_photo: File | null;
    hi_res: File | null;
    rights_confirmed: boolean;
    unit: Unit;
    size_reference: 'image' | 'outer';
    width_mm: number;
    height_mm: number;
    quantity: number;
    quality_tier_id: number | null;
    medium_id: number | null;
    substrate_id: number | null;
    edge_style: string | null;
    frame_id: number | null;
    frame_advice_requested: boolean;
    mat_id: number | null;
    mat_border_mm: number | null;
    glazing_id: number | null;
    finishing_ids: number[];
    placement: string;
    wall_width_mm: number | null;
    wall_height_mm: number | null;
    wall_colour: string;
    notes: string;
};

export function initialLineForm(line: EnquiryLine | null, options: CatalogOptions): LineForm {
    const standardTier = options.quality_tiers[0];
    return {
        source_type: line?.source_type ?? 'catalog',
        artwork_id: line?.artwork_id ?? null,
        title: line?.title ?? '',
        brief: {
            description: line?.brief?.description ?? '',
            style: line?.brief?.style ?? '',
            mood: line?.brief?.mood ?? [],
            palette: line?.brief?.palette ?? [],
            usage_rights: line?.brief?.usage_rights ?? 'private_display',
        },
        references: [],
        remove_media: [],
        own: { service: line?.own?.service ?? 'frame_only', condition_notes: line?.own?.condition_notes ?? '' },
        artwork_photo: null,
        hi_res: null,
        rights_confirmed: line?.rights_confirmed ?? false,
        unit: line?.unit ?? 'cm',
        size_reference: line?.size_reference ?? 'image',
        width_mm: line?.width_mm ?? 500,
        height_mm: line?.height_mm ?? 700,
        quantity: line?.quantity ?? 1,
        quality_tier_id: line ? line.quality_tier_id : (standardTier?.id ?? null),
        medium_id: line ? line.medium_id : (standardTier?.default_medium_id ?? null),
        substrate_id: line ? line.substrate_id : (standardTier?.default_substrate_id ?? null),
        edge_style: line?.edge_style ?? null,
        frame_id: line?.frame_id ?? null,
        frame_advice_requested: line?.frame_advice_requested ?? false,
        mat_id: line?.mat_id ?? null,
        mat_border_mm: line?.mat_border_mm ?? null,
        glazing_id: line ? line.glazing_id : (standardTier?.default_glazing_id ?? null),
        finishing_ids: line?.finishing_ids ?? options.finishings.filter((f) => f.is_default).map((f) => f.id),
        placement: line?.placement ?? '',
        wall_width_mm: line?.wall_width_mm ?? null,
        wall_height_mm: line?.wall_height_mm ?? null,
        wall_colour: line?.wall_colour ?? WALL_COLOURS[0].hex,
        notes: line?.notes ?? '',
    };
}
