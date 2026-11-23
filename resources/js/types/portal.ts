// Shapes of the data the Laravel portal controllers send (see app/Http/Presenters).

export interface Option<T = string> {
    value: T;
    label: string;
}

export interface MediaFile {
    id: number;
    name: string;
    url: string;
    thumb: string | null;
    is_image: boolean;
    size: string;
}

export interface Address {
    id: number;
    kind: 'billing' | 'delivery';
    label: string | null;
    contact_name: string | null;
    phone: string | null;
    line1: string;
    line2: string | null;
    city: string;
    pin_code: string;
    state_id: number;
    state: string | null;
    access_notes: string | null;
    is_default: boolean;
    one_line: string;
}

export type LineSourceType = 'catalog' | 'custom_creation' | 'own_artwork';

export interface EnquiryLine {
    id: number;
    position: number;
    source_type: LineSourceType;
    source_label: string;
    display_title: string;
    artwork_id: number | null;
    artwork: { code: string; title: string; artist: string | null } | null;
    title: string | null;
    brief: { description?: string; style?: string; mood?: string[]; palette?: string[]; usage_rights?: string };
    own: { service?: string; condition_notes?: string };
    rights_confirmed: boolean;
    unit: 'cm' | 'inch';
    size_reference: 'image' | 'outer';
    size_label: string;
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
    placement: string | null;
    wall_width_mm: number | null;
    wall_height_mm: number | null;
    wall_colour: string | null;
    notes: string | null;
    labels: {
        quality: string | null;
        medium: string | null;
        substrate: string | null;
        frame: string | null;
        mat: string | null;
        glazing: string | null;
        finishings: string[];
    };
    preview_image: string | null;
    detail_image?: string | null;
    full_image?: string | null;
    references: MediaFile[];
    artwork_photo: MediaFile | null;
    hi_res: MediaFile | null;
}

export interface EnquirySummary {
    id: number;
    reference: string | null;
    display_reference: string;
    project_name: string;
    status: string;
    status_label: string;
    status_color: string;
    lines_count: number;
    unread_count: number;
    thumbnail: string | null;
    submitted_at: string | null;
    updated_at: string | null;
    is_draft: boolean;
}

export interface EnquiryDetail extends EnquirySummary {
    customer_type: string | null;
    project_type: string | null;
    project_type_label: string | null;
    delivery_address_id: number | null;
    delivery_address: Address | null;
    required_by: string | null;
    budget_range: string | null;
    budget_range_label: string | null;
    installation_needed: boolean;
    preferred_contact: string | null;
    notes: string | null;
    can_cancel: boolean;
    can_message: boolean;
    lines: EnquiryLine[];
}

export interface EnquiryMessage {
    id: number;
    kind: string;
    is_question: boolean;
    is_system: boolean;
    from_staff: boolean;
    author: string;
    body: string;
    created_at: string;
    attachments: MediaFile[];
}

export interface CatalogArtwork {
    id: number;
    code: string;
    title: string;
    artist: string | null;
    type: string;
    type_label: string;
    description: string | null;
    categories: string[];
    thumb: string | null;
    image: string | null;
    image_detail?: string | null;
    image_full?: string | null;
    native_width_mm: number | null;
    native_height_mm: number | null;
    min_width_mm: number | null;
    max_width_mm: number | null;
    allow_custom_size: boolean;
    sizes: { width_mm: number; height_mm: number; label: string }[];
    default_medium_id: number | null;
    medium_ids: number[];
    substrate_ids: number[];
    is_featured: boolean;
}

export interface CatalogFrame {
    id: number;
    code: string;
    name: string;
    colour: string;
    width_mm: number;
    depth_mm: number;
    finish: string;
    material: string;
    corner: string | null;
    is_floater: boolean;
    profile: 'flat' | 'rounded' | 'scoop' | 'stepped' | 'ornate' | 'floater';
    substrate_ids: number[];
    max_inner_width_mm: number | null;
    max_inner_height_mm: number | null;
    /** Tileable photo strip of the moulding face (top row = outer edge, bottom row = sight edge). */
    strip_url?: string | null;
    strip_face_mm?: number | null;
}

export interface CatalogMat {
    id: number;
    name: string;
    colour: string;
    core_colour: string;
    default_border_mm: number;
    bottom_weight_mm: number;
}

export interface CatalogOptions {
    artworks: CatalogArtwork[];
    categories: string[];
    mediums: { id: number; name: string; kind: string; substrate_ids: number[]; description: string | null }[];
    substrates: {
        id: number;
        name: string;
        depth_mm: number;
        edge_styles: string[];
        allows_glazing: boolean;
        allows_mat: boolean;
        requires_frame: boolean;
        max_width_mm: number | null;
        max_height_mm: number | null;
    }[];
    frames: CatalogFrame[];
    mats: CatalogMat[];
    glazings: { id: number; name: string; material: string; reflection: string; max_area_m2: number | null; description: string | null }[];
    finishings: { id: number; name: string; description: string | null; picture: string | null; is_default: boolean; substrate_ids: number[] }[];
    quality_tiers: {
        id: number;
        name: string;
        description: string | null;
        default_medium_id: number | null;
        default_substrate_id: number | null;
        default_glazing_id: number | null;
        default_mat_id: number | null;
    }[];
    standard_sizes: { label: string; width_mm: number; height_mm: number }[];
    enums: {
        edge_styles: Option[];
        usage_rights: Option[];
        own_services: Option[];
        units: Option[];
        size_references: Option[];
    };
}

export interface ProjectOptions {
    project_types: Option[];
    budget_ranges: Option[];
    preferred_contacts: Option[];
    states: Option<number>[];
}

/** Customer-facing price estimate for one line (paise). */
export interface LineEstimate {
    mode: 'exact' | 'range' | 'hidden';
    is_range?: boolean;
    available?: boolean;
    complete?: boolean;
    pending?: string[];
    quantity?: number;
    unit_price?: number;
    line_total?: number;
    unit_low?: number;
    unit_high?: number;
    line_low?: number;
    line_high?: number;
    quantity_discount_percent?: number;
    rush_fee_percent?: number;
    items?: { label: string; amount: number }[];
}

export interface EnquiryEstimate {
    mode: 'exact' | 'range' | 'hidden';
    lines: Record<number, LineEstimate>;
    is_range?: boolean;
    complete?: boolean;
    subtotal_low?: number;
    subtotal_high?: number;
    tax_rate?: number;
    intra_state?: boolean;
    tax_low?: number;
    tax_high?: number;
    total_low?: number;
    total_high?: number;
}

export interface CustomerQuote {
    id: number;
    number: string;
    version: number;
    status: string;
    status_label: string;
    status_color: string;
    is_current: boolean;
    issue_date: string | null;
    valid_until: string | null;
    lead_time: string | null;
    payment_terms: string | null;
    note_to_customer: string | null;
    lines: {
        id: number;
        title: string;
        spec: string | null;
        quantity: number;
        unit_price: number;
        discount_amount: number;
        line_total: number;
        is_optional: boolean;
        is_selected: boolean;
    }[];
    extras: { name: string; inclusion: string; inclusion_label: string; amount: number }[];
    items_total: number;
    extras_total: number;
    discount_amount: number;
    discount_percent: number;
    taxable: number;
    intra_state: boolean;
    tax_rate: number;
    cgst: number;
    sgst: number;
    igst: number;
    grand_total: number;
    pdf_url: string;
    can_respond: boolean;
    is_expired: boolean;
    responded_at: string | null;
    response: { action: string; name?: string; role?: string; po_number?: string; comment?: string; reason?: string } | null;
}

export interface RejectionReasonOption {
    id: number;
    name: string;
    requires_comment: boolean;
}
