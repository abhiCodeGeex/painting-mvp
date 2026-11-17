import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { fits, mmToUnit, sizeLabel, unitToMm, type Unit } from '@/lib/units';
import { cn } from '@/lib/utils';
import type { CatalogArtwork, CatalogOptions, EnquiryLine, LineSourceType, MediaFile } from '@/types/portal';
import { useForm } from '@inertiajs/react';
import { Brush, Check, Frame as FrameIcon, ImagePlus, Images, LoaderCircle, Search, Upload, X } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { previewParts } from './preview-helpers';
import { PriceEstimateBox, useLiveEstimate } from './price-estimate';
import { FramedArt, WALL_COLOURS, WallPreview } from './wall-preview';

type LineForm = {
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
}

type SetLineData = {
    <K extends keyof LineForm>(key: K, value: LineForm[K]): void;
    (updater: (previous: LineForm) => LineForm): void;
};

function initialForm(line: EnquiryLine | null, options: CatalogOptions): LineForm {
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

interface Props {
    open: boolean;
    onClose: () => void;
    enquiryId: number;
    line: EnquiryLine | null;
    options: CatalogOptions;
}

/**
 * Add or edit one artwork on a draft enquiry, with a live, true-to-scale wall preview.
 */
export function LineEditor({ open, onClose, enquiryId, line, options }: Props) {
    const form = useForm<LineForm>(initialForm(line, options));
    const { data, setData, errors, processing } = form;
    const errs = errors as Record<string, string | undefined>;

    // Reset whenever the dialog opens for a different line.
    useEffect(() => {
        if (open) {
            // setData, not setDefaults()+reset(): defaults update asynchronously, so reset() would restore stale values.
            const fresh = initialForm(line, options);
            form.setDefaults(fresh);
            form.setData(fresh);
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, line?.id]);

    const artwork = options.artworks.find((a) => a.id === data.artwork_id) ?? null;
    const substrate = options.substrates.find((s) => s.id === data.substrate_id) ?? null;

    // ---- options filtered by what is already chosen (spec §4.3) ----
    const mediums = useMemo(
        () => options.mediums.filter((m) => !(data.source_type === 'catalog' && artwork?.medium_ids.length) || artwork!.medium_ids.includes(m.id)),
        [options.mediums, artwork, data.source_type],
    );
    const medium = options.mediums.find((m) => m.id === data.medium_id) ?? null;
    const substrates = useMemo(
        () =>
            options.substrates.filter(
                (s) =>
                    (!medium?.substrate_ids.length || medium.substrate_ids.includes(s.id)) &&
                    (data.source_type !== 'catalog' || !artwork?.substrate_ids.length || artwork.substrate_ids.includes(s.id)),
            ),
        [options.substrates, medium, artwork, data.source_type],
    );
    const matBorder = data.mat_id ? (data.mat_border_mm ?? options.mats.find((m) => m.id === data.mat_id)?.default_border_mm ?? 0) : 0;
    const frames = useMemo(
        () =>
            options.frames.filter(
                (f) =>
                    (!f.substrate_ids.length || (data.substrate_id !== null && f.substrate_ids.includes(data.substrate_id))) &&
                    fits(data.width_mm + 2 * matBorder, data.height_mm + 2 * matBorder, f.max_inner_width_mm, f.max_inner_height_mm),
            ),
        [options.frames, data.substrate_id, data.width_mm, data.height_mm, matBorder],
    );
    const areaM2 = (data.width_mm / 1000) * (data.height_mm / 1000);
    const glazings = useMemo(
        () =>
            options.glazings.filter((g) =>
                g.material === 'none' ? true : (!substrate || substrate.allows_glazing) && (!g.max_area_m2 || areaM2 <= g.max_area_m2),
            ),
        [options.glazings, substrate, areaM2],
    );
    const finishings = options.finishings.filter((f) => !f.substrate_ids.length || (data.substrate_id && f.substrate_ids.includes(data.substrate_id)));
    const matsAllowed = !substrate || substrate.allows_mat;

    // Friendly warnings instead of silently changing the customer's choices.
    const warnings: string[] = [];
    if (data.medium_id && !mediums.some((m) => m.id === data.medium_id)) warnings.push('The chosen medium is not available for this artwork.');
    if (data.substrate_id && !substrates.some((s) => s.id === data.substrate_id)) warnings.push('The chosen surface does not suit this medium.');
    if (data.frame_id && !frames.some((f) => f.id === data.frame_id)) warnings.push('The chosen frame does not fit this size or surface.');
    if (data.glazing_id && !glazings.some((g) => g.id === data.glazing_id)) warnings.push('The chosen glazing is not available for this size or surface.');
    if (data.mat_id && !matsAllowed) warnings.push(`${substrate?.name} cannot have a mat.`);
    if (substrate?.requires_frame && !data.frame_id && !data.frame_advice_requested) warnings.push(`${substrate.name} needs a frame — pick one or ask for our advice.`);

    // ---- preview image ----
    const [objectUrl, setObjectUrl] = useState<string | null>(null);
    const localFile = data.source_type === 'own_artwork' ? data.artwork_photo : data.source_type === 'custom_creation' ? (data.references[0] ?? null) : null;
    useEffect(() => {
        if (localFile && localFile.type.startsWith('image/')) {
            const url = URL.createObjectURL(localFile);
            setObjectUrl(url);
            return () => URL.revokeObjectURL(url);
        }
        setObjectUrl(null);
    }, [localFile]);

    const existingImage =
        data.source_type === 'own_artwork'
            ? line?.artwork_photo?.is_image
                ? line.artwork_photo.url
                : null
            : data.source_type === 'custom_creation'
              ? (line?.references.find((r) => r.is_image && !data.remove_media.includes(r.id))?.url ?? null)
              : null;
    const previewImage = data.source_type === 'catalog' ? (artwork?.image ?? null) : (objectUrl ?? existingImage);
    const parts = previewParts(options, data);
    // With "outer size", the picture is what's left inside the mat and frame.
    const surround = 2 * ((parts.frame?.width_mm ?? 0) + (data.mat_id && matsAllowed ? (parts.mat?.border_mm ?? 0) : 0));
    const pictureW = data.size_reference === 'outer' ? Math.max(50, data.width_mm - surround) : data.width_mm;
    const pictureH = data.size_reference === 'outer' ? Math.max(50, data.height_mm - surround) : data.height_mm;

    // ---- live price (calculated on the server) ----
    const { estimate, loading: estimating } = useLiveEstimate(
        enquiryId,
        {
            source_type: data.source_type,
            artwork_id: data.artwork_id,
            size_reference: data.size_reference,
            width_mm: data.width_mm,
            height_mm: data.height_mm,
            quantity: data.quantity,
            quality_tier_id: data.quality_tier_id,
            medium_id: data.medium_id,
            substrate_id: data.substrate_id,
            frame_id: data.frame_id,
            frame_advice_requested: data.frame_advice_requested,
            mat_id: matsAllowed ? data.mat_id : null,
            mat_border_mm: data.mat_id ? data.mat_border_mm : null,
            glazing_id: data.glazing_id,
            finishing_ids: data.finishing_ids,
            own: { service: data.own.service },
        },
        open,
    );

    function chooseArtwork(a: CatalogArtwork) {
        const size = a.sizes[0] ?? (a.native_width_mm && a.native_height_mm ? { width_mm: a.native_width_mm, height_mm: a.native_height_mm } : null);
        setData((d) => ({
            ...d,
            artwork_id: a.id,
            width_mm: size?.width_mm ?? d.width_mm,
            height_mm: size?.height_mm ?? d.height_mm,
            medium_id: a.default_medium_id ?? d.medium_id,
        }));
    }

    function applyTier(id: number) {
        const tier = options.quality_tiers.find((t) => t.id === id);
        if (!tier) return;
        setData((d) => ({
            ...d,
            quality_tier_id: id,
            medium_id: tier.default_medium_id ?? d.medium_id,
            substrate_id: tier.default_substrate_id ?? d.substrate_id,
            glazing_id: tier.default_glazing_id ?? d.glazing_id,
            mat_id: tier.default_mat_id ?? d.mat_id,
        }));
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        const url = line ? route('portal.enquiries.lines.update', [enquiryId, line.id]) : route('portal.enquiries.lines.store', enquiryId);
        form.transform((d) => ({
            ...d,
            mat_id: matsAllowed ? d.mat_id : null,
            frame_advice_requested: d.frame_id ? false : d.frame_advice_requested,
        }));
        form.post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    }

    const errorCount = Object.keys(errors).length;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="flex h-[95vh] max-h-[95vh] w-[98vw] max-w-6xl flex-col gap-0 overflow-hidden p-0 sm:max-w-6xl">
                <DialogHeader className="border-b px-6 py-4">
                    <DialogTitle>{line ? 'Edit artwork' : 'Add an artwork'}</DialogTitle>
                    <DialogDescription>Choose the artwork, size and framing. The preview on the right updates as you go.</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col lg:flex-row">
                    {/* ---------- left: the form ---------- */}
                    <div className="min-h-0 flex-1 space-y-8 overflow-y-auto px-6 py-6">
                        <Step n={1} title="What would you like?">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <SourceCard
                                    active={data.source_type === 'catalog'}
                                    onClick={() => setData('source_type', 'catalog')}
                                    icon={<Images className="size-6" />}
                                    title="From our catalog"
                                    text="Pick a painting or print we offer."
                                />
                                <SourceCard
                                    active={data.source_type === 'custom_creation'}
                                    onClick={() => setData('source_type', 'custom_creation')}
                                    icon={<Brush className="size-6" />}
                                    title="A new creation"
                                    text="We paint something new for you."
                                />
                                <SourceCard
                                    active={data.source_type === 'own_artwork'}
                                    onClick={() => setData('source_type', 'own_artwork')}
                                    icon={<FrameIcon className="size-6" />}
                                    title="My own artwork"
                                    text="Frame or reproduce a piece you have."
                                />
                            </div>
                        </Step>

                        {data.source_type === 'catalog' && (
                            <Step n={2} title="Choose an artwork">
                                <CatalogPicker options={options} selectedId={data.artwork_id} onSelect={chooseArtwork} />
                                <InputError message={errors.artwork_id} />
                            </Step>
                        )}

                        {data.source_type === 'custom_creation' && (
                            <Step n={2} title="Tell us about the artwork">
                                <Field label="Working title (optional)">
                                    <Input value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="e.g. Lobby triptych" />
                                </Field>
                                <Field label="Describe what you would like" error={errs['brief.description']}>
                                    <Textarea
                                        rows={4}
                                        value={data.brief.description}
                                        onChange={(e) => setData('brief', { ...data.brief, description: e.target.value })}
                                        placeholder="Subject, feeling, colours, anything that inspires you…"
                                    />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field label="Style">
                                        <NativeSelect value={data.brief.style} onChange={(e) => setData('brief', { ...data.brief, style: e.target.value })}>
                                            <option value="">No preference</option>
                                            {options.categories.map((c) => (
                                                <option key={c}>{c}</option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field label="Mood words (comma separated)">
                                        <Input
                                            defaultValue={data.brief.mood.join(', ')}
                                            onBlur={(e) =>
                                                setData('brief', {
                                                    ...data.brief,
                                                    mood: e.target.value
                                                        .split(',')
                                                        .map((s) => s.trim())
                                                        .filter(Boolean)
                                                        .slice(0, 10),
                                                })
                                            }
                                            placeholder="calm, warm, bold"
                                        />
                                    </Field>
                                </div>
                                <Field label="Colour palette (up to 6)">
                                    <PaletteInput value={data.brief.palette} wall={data.wall_colour} onChange={(p) => setData('brief', { ...data.brief, palette: p })} />
                                </Field>
                                <Field label="Where will it be displayed?" error={errs['brief.usage_rights']}>
                                    <ChoiceRow
                                        value={data.brief.usage_rights}
                                        options={options.enums.usage_rights}
                                        onChange={(v) => setData('brief', { ...data.brief, usage_rights: v })}
                                    />
                                </Field>
                                <Field label="Sketches or reference images" error={errors.references}>
                                    <FileGallery
                                        existing={(line?.references ?? []).filter((m) => !data.remove_media.includes(m.id))}
                                        added={data.references}
                                        onAdd={(files) => setData('references', [...data.references, ...files].slice(0, 10))}
                                        onRemoveAdded={(i) => setData('references', data.references.filter((_, j) => j !== i))}
                                        onRemoveExisting={(id) => setData('remove_media', [...data.remove_media, id])}
                                        multiple
                                    />
                                </Field>
                            </Step>
                        )}

                        {data.source_type === 'own_artwork' && (
                            <Step n={2} title="Your artwork">
                                <Field label="What should we do?" error={errs['own.service']}>
                                    <ChoiceRow value={data.own.service} options={options.enums.own_services} onChange={(v) => setData('own', { ...data.own, service: v })} />
                                </Field>
                                <Field label="Photo of the artwork" error={errors.artwork_photo}>
                                    <FileGallery
                                        existing={line?.artwork_photo ? [line.artwork_photo] : []}
                                        added={data.artwork_photo ? [data.artwork_photo] : []}
                                        onAdd={(files) => setData('artwork_photo', files[0] ?? null)}
                                        onRemoveAdded={() => setData('artwork_photo', null)}
                                    />
                                </Field>
                                {data.own.service === 'reproduce_and_frame' && (
                                    <Field label="High-resolution file for printing (optional now)" error={errors.hi_res} hint="TIFF, PNG, JPG or PDF up to 200 MB.">
                                        <FileGallery
                                            existing={line?.hi_res ? [line.hi_res] : []}
                                            added={data.hi_res ? [data.hi_res] : []}
                                            onAdd={(files) => setData('hi_res', files[0] ?? null)}
                                            onRemoveAdded={() => setData('hi_res', null)}
                                            accept=".jpg,.jpeg,.png,.tif,.tiff,.pdf,.psd,.ai"
                                        />
                                    </Field>
                                )}
                                <Field label="Condition notes (optional)">
                                    <Textarea
                                        rows={2}
                                        value={data.own.condition_notes}
                                        onChange={(e) => setData('own', { ...data.own, condition_notes: e.target.value })}
                                        placeholder="e.g. Rolled canvas, slight crease at the corner"
                                    />
                                </Field>
                                <label className="flex items-start gap-3 text-sm">
                                    <Checkbox checked={data.rights_confirmed} onCheckedChange={(v) => setData('rights_confirmed', v === true)} />
                                    <span>I own the rights to this image or have permission to reproduce it.</span>
                                </label>
                                <InputError message={errors.rights_confirmed} />
                            </Step>
                        )}

                        <Step n={3} title="Size & quantity">
                            <SizeFields data={data} setData={setData} artwork={data.source_type === 'catalog' ? artwork : null} options={options} />
                            <InputError message={errors.width_mm ?? errors.height_mm} />
                        </Step>

                        <Step n={4} title="Quality & materials">
                            <Field label="Quality">
                                <div className="grid gap-2 sm:grid-cols-3">
                                    {options.quality_tiers.map((t) => (
                                        <button
                                            type="button"
                                            key={t.id}
                                            onClick={() => applyTier(t.id)}
                                            className={cn('rounded-lg border p-3 text-left text-sm transition', data.quality_tier_id === t.id ? 'border-primary ring-primary ring-2' : 'hover:bg-muted')}
                                        >
                                            <div className="font-medium">{t.name}</div>
                                            <div className="text-muted-foreground text-xs">{t.description}</div>
                                        </button>
                                    ))}
                                </div>
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Medium" error={errors.medium_id}>
                                    <NativeSelect value={data.medium_id ?? ''} onChange={(e) => setData('medium_id', e.target.value ? Number(e.target.value) : null)}>
                                        <option value="">Not sure — advise me</option>
                                        {mediums.map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {m.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field label="Surface" error={errors.substrate_id}>
                                    <NativeSelect value={data.substrate_id ?? ''} onChange={(e) => setData('substrate_id', e.target.value ? Number(e.target.value) : null)}>
                                        <option value="">Not sure — advise me</option>
                                        {substrates.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </div>
                            {substrate && substrate.depth_mm > 0 && substrate.edge_styles.length > 0 && (
                                <Field label="Canvas edges">
                                    <ChoiceRow
                                        value={data.edge_style ?? ''}
                                        options={options.enums.edge_styles.filter((o) => substrate.edge_styles.includes(o.value))}
                                        onChange={(v) => setData('edge_style', v)}
                                    />
                                </Field>
                            )}
                        </Step>

                        <Step n={5} title="Framing">
                            <Field label="Frame" error={errors.frame_id}>
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                    <OptionTile
                                        active={!data.frame_id && !data.frame_advice_requested}
                                        onClick={() => setData((d) => ({ ...d, frame_id: null, frame_advice_requested: false }))}
                                        label="No frame"
                                    >
                                        <span className="block h-12 w-9 rounded-sm" style={{ background: 'linear-gradient(135deg,#f6d365,#a1c4fd)' }} />
                                    </OptionTile>
                                    <OptionTile
                                        active={data.frame_advice_requested && !data.frame_id}
                                        onClick={() => setData((d) => ({ ...d, frame_id: null, frame_advice_requested: true }))}
                                        label="Advise me"
                                    >
                                        <span className="text-muted-foreground flex h-12 items-center text-2xl">?</span>
                                    </OptionTile>
                                    {frames.map((f) => (
                                        <OptionTile
                                            key={f.id}
                                            active={data.frame_id === f.id}
                                            onClick={() => setData((d) => ({ ...d, frame_id: f.id, frame_advice_requested: false }))}
                                            label={f.name}
                                            sub={`${f.width_mm} mm · ${f.material}`}
                                        >
                                            <FramedArt
                                                imageUrl={null}
                                                widthMm={300}
                                                heightMm={400}
                                                frame={{ colour: f.colour, width_mm: f.width_mm, finish: f.finish }}
                                                mat={null}
                                                scale={48 / (400 + 2 * f.width_mm)}
                                            />
                                        </OptionTile>
                                    ))}
                                </div>
                            </Field>

                            {matsAllowed && (
                                <Field label="Mat (border inside the frame)" error={errors.mat_id}>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <SwatchButton active={!data.mat_id} onClick={() => setData('mat_id', null)} label="None" colour="transparent" />
                                        {options.mats.map((m) => (
                                            <SwatchButton key={m.id} active={data.mat_id === m.id} onClick={() => setData('mat_id', m.id)} label={m.name} colour={m.colour} />
                                        ))}
                                        {data.mat_id && (
                                            <div className="flex items-center gap-2 text-sm">
                                                <span className="text-muted-foreground">Border</span>
                                                <Input
                                                    type="number"
                                                    className="w-20"
                                                    min={1}
                                                    value={mmToUnit(matBorder, data.unit)}
                                                    onChange={(e) => setData('mat_border_mm', unitToMm(e.target.value, data.unit) || null)}
                                                />
                                                <span className="text-muted-foreground">{data.unit === 'cm' ? 'cm' : 'in'}</span>
                                            </div>
                                        )}
                                    </div>
                                </Field>
                            )}

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Glass / glazing" error={errors.glazing_id}>
                                    <NativeSelect value={data.glazing_id ?? ''} onChange={(e) => setData('glazing_id', e.target.value ? Number(e.target.value) : null)}>
                                        <option value="">Not sure — advise me</option>
                                        {glazings.map((g) => (
                                            <option key={g.id} value={g.id}>
                                                {g.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                {finishings.length > 0 && (
                                    <Field label="Hanging & finishing">
                                        <div className="space-y-1.5">
                                            {finishings.map((f) => (
                                                <label key={f.id} className="flex items-center gap-2 text-sm">
                                                    <Checkbox
                                                        checked={data.finishing_ids.includes(f.id)}
                                                        onCheckedChange={(v) =>
                                                            setData('finishing_ids', v === true ? [...data.finishing_ids, f.id] : data.finishing_ids.filter((x) => x !== f.id))
                                                        }
                                                    />
                                                    {f.name}
                                                </label>
                                            ))}
                                        </div>
                                    </Field>
                                )}
                            </div>
                        </Step>

                        <Step n={6} title="Where will it hang?">
                            <Field label="Wall colour" hint="Used for the preview only.">
                                <div className="flex flex-wrap items-center gap-2">
                                    {WALL_COLOURS.map((c) => (
                                        <SwatchButton key={c.hex} active={data.wall_colour === c.hex} onClick={() => setData('wall_colour', c.hex)} label={c.name} colour={c.hex} />
                                    ))}
                                    <label className="flex items-center gap-1 text-xs">
                                        <input type="color" value={data.wall_colour} onChange={(e) => setData('wall_colour', e.target.value.toUpperCase())} className="size-8 cursor-pointer rounded border" />
                                        Custom
                                    </label>
                                </div>
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field label="Room / placement">
                                    <Input value={data.placement} onChange={(e) => setData('placement', e.target.value)} placeholder="e.g. Living room, above sofa" />
                                </Field>
                                <Field label={`Wall width (${data.unit === 'cm' ? 'cm' : 'in'}, optional)`}>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={mmToUnit(data.wall_width_mm, data.unit)}
                                        onChange={(e) => setData('wall_width_mm', e.target.value ? unitToMm(e.target.value, data.unit) : null)}
                                    />
                                </Field>
                                <Field label={`Wall height (${data.unit === 'cm' ? 'cm' : 'in'}, optional)`}>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={mmToUnit(data.wall_height_mm, data.unit)}
                                        onChange={(e) => setData('wall_height_mm', e.target.value ? unitToMm(e.target.value, data.unit) : null)}
                                    />
                                </Field>
                            </div>
                            <Field label="Anything else we should know? (optional)">
                                <Textarea rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                            </Field>
                        </Step>
                    </div>

                    {/* ---------- right: live preview ---------- */}
                    {/* The panel scrolls on its own; the action buttons stay pinned at the bottom. */}
                    <aside className="bg-muted/30 flex max-h-[55%] shrink-0 flex-col border-t lg:max-h-none lg:min-h-0 lg:w-[440px] lg:border-t-0 lg:border-l">
                        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4 lg:p-6">
                            <WallPreview
                                imageUrl={previewImage}
                                widthMm={pictureW}
                                heightMm={pictureH}
                                frame={parts.frame}
                                mat={data.mat_id && matsAllowed ? parts.mat : null}
                                depthMm={parts.depthMm}
                                edgeStyle={data.edge_style}
                                glazing={parts.glazing}
                                wallColour={data.wall_colour}
                                wallWidthMm={data.wall_width_mm}
                                wallHeightMm={data.wall_height_mm}
                                unit={data.unit}
                                quantity={data.quantity}
                            />
                            <p className="text-muted-foreground hidden text-xs lg:block">
                                Preview is indicative: colours and textures will differ slightly from the real piece.
                            </p>

                            <PriceEstimateBox estimate={estimate} loading={estimating} />

                            {warnings.length > 0 && (
                                <ul className="space-y-1 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                    {warnings.map((w) => (
                                        <li key={w}>• {w}</li>
                                    ))}
                                </ul>
                            )}

                            {errorCount > 0 && (
                                <p className="rounded-md border border-red-300 bg-red-50 p-3 text-xs text-red-800 dark:bg-red-950/40 dark:text-red-200">
                                    Please check the {errorCount === 1 ? 'highlighted field' : `${errorCount} highlighted fields`}.
                                </p>
                            )}
                        </div>

                        <div className="bg-background/80 flex gap-2 border-t px-4 py-3 lg:px-6">
                            <Button type="button" variant="outline" onClick={onClose} className="flex-1">
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing} className="flex-1">
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                {line ? 'Save changes' : 'Add to enquiry'}
                            </Button>
                        </div>
                    </aside>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ------------------------------------------------------------------ */

function Step({ n, title, children }: { n: number; title: string; children: ReactNode }) {
    return (
        <section className="space-y-4">
            <h3 className="flex items-center gap-2 text-base font-semibold">
                <span className="bg-primary text-primary-foreground flex size-6 items-center justify-center rounded-full text-xs">{n}</span>
                {title}
            </h3>
            {children}
        </section>
    );
}

function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            {children}
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

function SourceCard({ active, onClick, icon, title, text }: { active: boolean; onClick: () => void; icon: ReactNode; title: string; text: string }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('flex flex-col items-start gap-2 rounded-xl border p-4 text-left transition', active ? 'border-primary ring-primary bg-primary/5 ring-2' : 'hover:bg-muted')}
        >
            {icon}
            <span className="font-medium">{title}</span>
            <span className="text-muted-foreground text-xs">{text}</span>
        </button>
    );
}

function ChoiceRow({ value, options, onChange }: { value: string; options: { value: string; label: string }[]; onChange: (v: string) => void }) {
    return (
        <div className="flex flex-wrap gap-2">
            {options.map((o) => (
                <button
                    type="button"
                    key={o.value}
                    onClick={() => onChange(o.value)}
                    className={cn('rounded-full border px-3 py-1.5 text-sm transition', value === o.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

function OptionTile({ active, onClick, label, sub, children }: { active: boolean; onClick: () => void; label: string; sub?: string; children: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn('flex flex-col items-center gap-2 rounded-lg border p-3 text-center transition', active ? 'border-primary ring-primary ring-2' : 'hover:bg-muted')}
        >
            <span className="flex h-14 items-center justify-center">{children}</span>
            <span className="text-xs leading-tight font-medium">{label}</span>
            {sub && <span className="text-muted-foreground -mt-1 text-[11px]">{sub}</span>}
        </button>
    );
}

function SwatchButton({ active, onClick, label, colour }: { active: boolean; onClick: () => void; label: string; colour: string }) {
    return (
        <button
            type="button"
            title={label}
            onClick={onClick}
            className={cn('flex items-center gap-1.5 rounded-full border py-1 pr-3 pl-1 text-xs transition', active ? 'border-primary ring-primary ring-2' : 'hover:bg-muted')}
        >
            <span
                className="size-6 rounded-full border"
                style={{ background: colour === 'transparent' ? 'repeating-linear-gradient(45deg,#ddd 0 4px,#fff 4px 8px)' : colour }}
            />
            {label}
        </button>
    );
}

function PaletteInput({ value, wall, onChange }: { value: string[]; wall: string; onChange: (p: string[]) => void }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            {value.map((c, i) => (
                <span key={i} className="relative">
                    <input
                        type="color"
                        value={c}
                        onChange={(e) => onChange(value.map((x, j) => (j === i ? e.target.value.toUpperCase() : x)))}
                        className="size-9 cursor-pointer rounded border"
                    />
                    <button
                        type="button"
                        onClick={() => onChange(value.filter((_, j) => j !== i))}
                        className="bg-background absolute -top-1.5 -right-1.5 rounded-full border p-0.5"
                    >
                        <X className="size-3" />
                    </button>
                </span>
            ))}
            {value.length < 6 && (
                <>
                    <Button type="button" variant="outline" size="sm" onClick={() => onChange([...value, '#6B8E9F'])}>
                        Add colour
                    </Button>
                    {!value.includes(wall) && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => onChange([...value, wall])}>
                            Match my wall
                        </Button>
                    )}
                </>
            )}
        </div>
    );
}

function FileGallery({
    existing,
    added,
    onAdd,
    onRemoveAdded,
    onRemoveExisting,
    multiple = false,
    accept = 'image/*,.pdf',
}: {
    existing: MediaFile[];
    added: File[];
    onAdd: (files: File[]) => void;
    onRemoveAdded: (index: number) => void;
    onRemoveExisting?: (id: number) => void;
    multiple?: boolean;
    accept?: string;
}) {
    const canAdd = multiple || existing.length + added.length === 0 || !multiple;

    return (
        <div className="flex flex-wrap gap-3">
            {existing.map((m) => (
                <FileThumb key={`e${m.id}`} name={m.name} src={m.thumb} onRemove={onRemoveExisting ? () => onRemoveExisting(m.id) : undefined} />
            ))}
            {added.map((f, i) => (
                <LocalThumb key={`a${i}`} file={f} onRemove={() => onRemoveAdded(i)} />
            ))}
            {canAdd && (
                <label className="hover:bg-muted flex size-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed text-xs">
                    {multiple ? <ImagePlus className="size-5" /> : <Upload className="size-5" />}
                    {multiple ? 'Add files' : existing.length + added.length ? 'Replace' : 'Upload'}
                    <input
                        type="file"
                        className="hidden"
                        accept={accept}
                        multiple={multiple}
                        onChange={(e) => {
                            onAdd(Array.from(e.target.files ?? []));
                            e.target.value = '';
                        }}
                    />
                </label>
            )}
        </div>
    );
}

function LocalThumb({ file, onRemove }: { file: File; onRemove: () => void }) {
    const [src, setSrc] = useState<string | null>(null);
    useEffect(() => {
        if (!file.type.startsWith('image/')) return;
        const url = URL.createObjectURL(file);
        setSrc(url);
        return () => URL.revokeObjectURL(url);
    }, [file]);
    return <FileThumb name={file.name} src={src} onRemove={onRemove} />;
}

function FileThumb({ name, src, onRemove }: { name: string; src: string | null; onRemove?: () => void }) {
    return (
        <div className="relative w-24">
            {src ? (
                <img src={src} alt="" className="size-24 rounded-lg border object-cover" />
            ) : (
                <div className="bg-muted flex size-24 items-center justify-center rounded-lg border text-xs font-semibold uppercase">{name.split('.').pop()}</div>
            )}
            <p className="mt-1 truncate text-[11px]">{name}</p>
            {onRemove && (
                <button type="button" onClick={onRemove} className="bg-background absolute -top-2 -right-2 rounded-full border p-1 shadow-sm" title="Remove">
                    <X className="size-3" />
                </button>
            )}
        </div>
    );
}

function CatalogPicker({ options, selectedId, onSelect }: { options: CatalogOptions; selectedId: number | null; onSelect: (a: CatalogArtwork) => void }) {
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState('');
    const list = options.artworks.filter(
        (a) =>
            (!query || `${a.title} ${a.artist ?? ''} ${a.code}`.toLowerCase().includes(query.toLowerCase())) &&
            (!category || a.categories.includes(category)),
    );

    return (
        <div className="space-y-3">
            <div className="flex flex-col gap-2 sm:flex-row">
                <div className="relative flex-1">
                    <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                    <Input className="pl-8" placeholder="Search title or artist" value={query} onChange={(e) => setQuery(e.target.value)} />
                </div>
                <NativeSelect className="sm:w-48" value={category} onChange={(e) => setCategory(e.target.value)}>
                    <option value="">All categories</option>
                    {options.categories.map((c) => (
                        <option key={c}>{c}</option>
                    ))}
                </NativeSelect>
            </div>
            {list.length === 0 && <p className="text-muted-foreground text-sm">No artworks match your search.</p>}
            <div className="grid max-h-96 grid-cols-2 gap-3 overflow-y-auto p-1 sm:grid-cols-3">
                {list.map((a) => (
                    <button
                        type="button"
                        key={a.id}
                        onClick={() => onSelect(a)}
                        className={cn(
                            'group relative overflow-hidden rounded-lg border text-left transition',
                            selectedId === a.id ? 'border-primary ring-primary ring-2' : 'hover:shadow-md',
                        )}
                    >
                        <div className="bg-muted aspect-square w-full">
                            {a.thumb ? (
                                <img src={a.thumb} alt={a.title} className="size-full object-cover" loading="lazy" />
                            ) : (
                                <div className="size-full" style={{ background: 'linear-gradient(135deg,#f6d365,#fda085,#a1c4fd)' }} />
                            )}
                        </div>
                        <div className="p-2">
                            <div className="truncate text-sm font-medium">{a.title}</div>
                            <div className="text-muted-foreground truncate text-xs">
                                {a.artist ?? 'Studio'} · {a.type_label}
                            </div>
                        </div>
                        {selectedId === a.id && (
                            <span className="bg-primary text-primary-foreground absolute top-2 right-2 rounded-full p-1">
                                <Check className="size-3" />
                            </span>
                        )}
                    </button>
                ))}
            </div>
        </div>
    );
}

function SizeFields({
    data,
    setData,
    artwork,
    options,
}: {
    data: LineForm;
    setData: SetLineData;
    artwork: CatalogArtwork | null;
    options: CatalogOptions;
}) {
    const fixedSizes = artwork && !artwork.allow_custom_size;
    const presets = artwork?.sizes.length ? artwork.sizes : options.standard_sizes;
    const [w, setW] = useState(mmToUnit(data.width_mm, data.unit));
    const [h, setH] = useState(mmToUnit(data.height_mm, data.unit));

    // Keep the text boxes in sync when size changes from elsewhere (preset, artwork, unit).
    useEffect(() => {
        setW(mmToUnit(data.width_mm, data.unit));
        setH(mmToUnit(data.height_mm, data.unit));
    }, [data.width_mm, data.height_mm, data.unit]);

    const unitLabel = data.unit === 'cm' ? 'cm' : 'in';

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-4">
                <ChoiceRow value={data.unit} options={options.enums.units} onChange={(v) => setData('unit', v as Unit)} />
                <div className="text-muted-foreground text-xs">Sizes are saved exactly; switch units any time.</div>
            </div>

            <div>
                <Label className="mb-1.5 block">{fixedSizes ? 'Available sizes' : 'Popular sizes'}</Label>
                <div className="flex flex-wrap gap-2">
                    {presets.map((s) => {
                        const active = s.width_mm === data.width_mm && s.height_mm === data.height_mm;
                        return (
                            <button
                                type="button"
                                key={`${s.width_mm}x${s.height_mm}`}
                                onClick={() => setData((d) => ({ ...d, width_mm: s.width_mm, height_mm: s.height_mm }))}
                                className={cn('rounded-md border px-2.5 py-1 text-xs transition', active ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')}
                            >
                                {data.unit === 'cm' && 'label' in s && s.label ? s.label : sizeLabel(s.width_mm, s.height_mm, data.unit)}
                            </button>
                        );
                    })}
                </div>
            </div>

            {!fixedSizes && (
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div className="grid gap-1.5">
                        <Label>Width ({unitLabel})</Label>
                        <Input
                            type="number"
                            min={1}
                            step="0.1"
                            value={w}
                            onChange={(e) => setW(e.target.value)}
                            onBlur={() => setData('width_mm', unitToMm(w, data.unit))}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Height ({unitLabel})</Label>
                        <Input
                            type="number"
                            min={1}
                            step="0.1"
                            value={h}
                            onChange={(e) => setH(e.target.value)}
                            onBlur={() => setData('height_mm', unitToMm(h, data.unit))}
                        />
                    </div>
                    <div className="grid gap-1.5">
                        <Label>Quantity</Label>
                        <Input type="number" min={1} max={1000} value={data.quantity} onChange={(e) => setData('quantity', Math.max(1, Number(e.target.value) || 1))} />
                    </div>
                    <div className="flex items-end">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setData((d) => ({ ...d, width_mm: d.height_mm, height_mm: d.width_mm }))}
                        >
                            ⟲ Rotate
                        </Button>
                    </div>
                </div>
            )}
            {fixedSizes && (
                <div className="grid w-40 gap-1.5">
                    <Label>Quantity</Label>
                    <Input type="number" min={1} max={1000} value={data.quantity} onChange={(e) => setData('quantity', Math.max(1, Number(e.target.value) || 1))} />
                </div>
            )}

            <div>
                <Label className="mb-1.5 block">These measurements are…</Label>
                <ChoiceRow
                    value={data.size_reference}
                    options={options.enums.size_references}
                    onChange={(v) => setData('size_reference', v as 'image' | 'outer')}
                />
                <p className="text-muted-foreground mt-1.5 text-xs">
                    “Artwork / image size” is the picture only. “Outer size” includes the mat and frame — choose it if you have a fixed space on the wall.
                </p>
            </div>
        </div>
    );
}
