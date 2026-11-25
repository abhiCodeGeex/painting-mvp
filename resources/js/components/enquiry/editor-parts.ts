import type { InertiaForm } from '@/inertia/form';
import { mmToUnit, sizeLabel, unitToMm, type Unit } from '@/lib/units';
import type { CatalogArtwork, CatalogOptions, MediaFile, Option } from '@/types/portal';
import { Icon } from '@/ui/icon';
import { UI } from '@/ui/primitives';
import {
    ChangeDetectionStrategy,
    Component,
    computed,
    DestroyRef,
    effect,
    ElementRef,
    inject,
    input,
    output,
    signal,
    viewChild,
} from '@angular/core';
import { Check, ImagePlus, Maximize2, Search, Upload, X } from 'lucide';
import { ArtworkLightbox } from './artwork-lightbox';
import type { LineForm } from './line-form';

/* ------------------------------------------------------------------ */

@Component({
    selector: 'choice-row',
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex flex-wrap gap-2">
            @for (o of options(); track o.value) {
                <button
                    type="button"
                    [class]="
                        'rounded-full border px-3 py-1.5 text-sm transition ' +
                        (value() === o.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted')
                    "
                    (click)="changed.emit(o.value)"
                >
                    {{ o.label }}
                </button>
            }
        </div>
    `,
})
export class ChoiceRow {
    readonly value = input<string | null>(null);
    readonly options = input.required<Option[]>();
    readonly changed = output<string>();
}

/* ------------------------------------------------------------------ */

@Component({
    selector: 'file-thumb',
    imports: [Icon],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="relative w-24">
            @if (src()) {
                <img [src]="src()" alt="" class="size-24 rounded-lg border object-cover" />
            } @else {
                <div class="bg-muted flex size-24 items-center justify-center rounded-lg border text-xs font-semibold uppercase">
                    {{ extension() }}
                </div>
            }
            <p class="mt-1 truncate text-[11px]">{{ name() }}</p>
            @if (removable()) {
                <button
                    type="button"
                    class="bg-background absolute -top-2 -right-2 rounded-full border p-1 shadow-sm"
                    title="Remove"
                    (click)="removed.emit()"
                >
                    <svg [icon]="X" class="size-3" />
                </button>
            }
        </div>
    `,
})
export class FileThumb {
    readonly name = input.required<string>();
    readonly src = input<string | null>(null);
    readonly removable = input(true);
    readonly removed = output<void>();
    protected extension = computed(() => this.name().split('.').pop());
    protected readonly X = X;
}

/** Thumbnail for a file picked on this device (object URL preview). */
@Component({
    selector: 'local-thumb',
    imports: [FileThumb],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `<file-thumb [name]="file().name" [src]="src()" (removed)="removed.emit()" />`,
})
export class LocalThumb {
    readonly file = input.required<File>();
    readonly removed = output<void>();
    protected src = signal<string | null>(null);

    constructor() {
        let url: string | null = null;
        effect(() => {
            if (url) URL.revokeObjectURL(url);
            const file = this.file();
            url = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
            this.src.set(url);
        });
        inject(DestroyRef).onDestroy(() => url && URL.revokeObjectURL(url));
    }
}

@Component({
    selector: 'file-gallery',
    imports: [Icon, FileThumb, LocalThumb],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex flex-wrap gap-3">
            @for (m of existing(); track m.id) {
                <file-thumb [name]="m.name" [src]="m.thumb" [removable]="removableExisting()" (removed)="removeExisting.emit(m.id)" />
            }
            @for (f of added(); track $index) {
                <local-thumb [file]="f" (removed)="removeAdded.emit($index)" />
            }
            <label
                class="hover:bg-muted flex size-24 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed text-xs"
            >
                <svg [icon]="multiple() ? ImagePlus : Upload" class="size-5" />
                {{ multiple() ? 'Add files' : existing().length + added().length ? 'Replace' : 'Upload' }}
                <input type="file" class="hidden" [accept]="accept()" [multiple]="multiple()" (change)="pick($event)" />
            </label>
        </div>
    `,
})
export class FileGallery {
    readonly existing = input<MediaFile[]>([]);
    readonly added = input<File[]>([]);
    readonly multiple = input(false);
    readonly accept = input('image/*,.pdf');
    readonly removableExisting = input(false);
    readonly add = output<File[]>();
    readonly removeAdded = output<number>();
    readonly removeExisting = output<number>();
    protected readonly ImagePlus = ImagePlus;
    protected readonly Upload = Upload;

    pick(event: Event): void {
        const input = event.target as HTMLInputElement;
        this.add.emit(Array.from(input.files ?? []));
        input.value = '';
    }
}

/* ------------------------------------------------------------------ */

@Component({
    selector: 'catalog-picker',
    imports: [Icon, UI, ArtworkLightbox],
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: { class: 'block @container' },
    template: `
        <div class="space-y-3">
            @if (chosen(); as c) {
                <div class="bg-muted/40 flex items-center gap-3 rounded-lg border p-2 pr-3" aria-live="polite">
                    <div class="bg-muted size-11 shrink-0 overflow-hidden rounded-md border">
                        @if (c.thumb) {
                            <img [src]="c.thumb" alt="" class="size-full object-cover" />
                        } @else {
                            <div class="size-full" style="background: linear-gradient(135deg, #f6d365, #fda085, #a1c4fd)"></div>
                        }
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">Selected</p>
                        <p class="truncate text-sm leading-tight font-medium">{{ c.title }}</p>
                        <p class="text-muted-foreground truncate text-xs">{{ c.artist ?? 'Studio' }} · {{ c.type_label }}</p>
                    </div>
                    <button
                        ui-button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="h-8 shrink-0"
                        [attr.aria-label]="'Change artwork (currently ' + c.title + ')'"
                        (click)="focusGrid()"
                    >
                        Change
                    </button>
                </div>
            }

            <div class="flex gap-2">
                <div class="relative min-w-0 flex-1">
                    <svg [icon]="Search" class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                    <input
                        #search
                        ui-input
                        type="search"
                        class="pl-8"
                        placeholder="Search title or artist"
                        aria-label="Search artworks by title or artist"
                        [value]="query()"
                        (input)="query.set($any($event.target).value); limit.set(pageSize)"
                    />
                </div>
                <select
                    ui-select
                    class="w-2/5 shrink-0"
                    aria-label="Filter by category"
                    [value]="category()"
                    (change)="category.set($any($event.target).value); limit.set(pageSize)"
                >
                    <option value="">All categories</option>
                    @for (c of options().categories; track c) {
                        <option [value]="c">
                            @if (c.includes(' › ')) {
                                &nbsp;&nbsp;↳ {{ c.split(' › ')[1] }}
                            } @else {
                                {{ c }}
                            }
                        </option>
                    }
                </select>
            </div>

            @if (list().length === 0) {
                <div class="flex flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center">
                    <svg [icon]="Search" class="text-muted-foreground size-5" />
                    <p class="text-sm font-medium">No artworks match</p>
                    <p class="text-muted-foreground text-xs">Try a different title, artist or category.</p>
                    <button ui-button type="button" variant="outline" size="sm" class="mt-1 h-8" (click)="clearFilters()">
                        <svg [icon]="X" />
                        Clear search
                    </button>
                </div>
            } @else {
                <div #grid class="grid scroll-mt-4 grid-cols-2 gap-2.5 @sm:grid-cols-3" role="list" aria-label="Artworks">
                    @for (a of visible(); track a.id) {
                        <div class="group relative" role="listitem">
                            <button
                                type="button"
                                data-tile
                                [attr.data-selected]="selectedId() === a.id || null"
                                [attr.aria-pressed]="selectedId() === a.id"
                                [attr.aria-label]="a.title + ', ' + (a.artist ?? 'Studio') + ', ' + a.type_label"
                                [class]="
                                    'focus-visible:ring-ring focus-visible:ring-offset-background relative block w-full overflow-hidden rounded-lg border text-left transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-hidden ' +
                                    (selectedId() === a.id ? 'border-primary ring-primary ring-2' : 'hover:border-foreground/25 hover:shadow-sm')
                                "
                                (click)="selected.emit(a)"
                            >
                                <div class="bg-muted aspect-[4/3] w-full">
                                    @if (a.thumb) {
                                        <img [src]="a.thumb" alt="" class="size-full object-cover" loading="lazy" />
                                    } @else {
                                        <div class="size-full" style="background: linear-gradient(135deg, #f6d365, #fda085, #a1c4fd)"></div>
                                    }
                                </div>
                                <div class="px-2 py-1.5">
                                    <div class="truncate text-[13px] leading-snug font-medium">{{ a.title }}</div>
                                    <div class="text-muted-foreground truncate text-[11px] leading-snug">
                                        {{ a.artist ?? 'Studio' }} · {{ a.type_label }}
                                    </div>
                                </div>
                                @if (selectedId() === a.id) {
                                    <span
                                        class="bg-primary text-primary-foreground ring-background absolute top-1.5 right-1.5 rounded-full p-1 shadow ring-2"
                                        aria-hidden="true"
                                    >
                                        <svg [icon]="Check" class="size-3" />
                                    </span>
                                }
                            </button>
                            @if (a.image) {
                                <button
                                    type="button"
                                    class="absolute top-1.5 left-1.5 rounded-full bg-black/55 p-1.5 text-white shadow transition hover:bg-black/75 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-hidden pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100 pointer-fine:focus-visible:opacity-100"
                                    [title]="'View ' + a.title + ' full size'"
                                    [attr.aria-label]="'View ' + a.title + ' full size'"
                                    (click)="zoomed.set(a)"
                                >
                                    <svg [icon]="Maximize2" class="size-3.5" />
                                </button>
                            }
                        </div>
                    }
                </div>
                @if (list().length > visible().length) {
                    <button
                        ui-button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground hover:text-foreground w-full"
                        (click)="limit.set(limit() + pageSize * 2)"
                    >
                        Show more artworks
                        <span class="font-normal opacity-75">({{ list().length - visible().length }} more)</span>
                    </button>
                }
            }
        </div>
        @if (zoomed(); as z) {
            <artwork-lightbox
                [open]="true"
                (openChange)="!$event && zoomed.set(null)"
                [title]="z.title + (z.artist ? ' — ' + z.artist : '')"
                [previewUrl]="z.image"
                [fullUrl]="z.image_full || z.image_detail || z.image"
                [widthMm]="z.native_width_mm || 600"
                [heightMm]="z.native_height_mm || 800"
            />
        }
    `,
})
export class CatalogPicker {
    readonly options = input.required<CatalogOptions>();
    readonly selectedId = input<number | null>(null);
    readonly selected = output<CatalogArtwork>();
    protected query = signal('');
    protected category = signal('');
    protected readonly Search = Search;
    protected readonly Check = Check;
    protected readonly Maximize2 = Maximize2;
    protected readonly X = X;
    private readonly grid = viewChild<ElementRef<HTMLElement>>('grid');
    private readonly search = viewChild<ElementRef<HTMLInputElement>>('search');
    /** The artwork shown full size (no frame yet: chosen later in the editor). */
    protected zoomed = signal<CatalogArtwork | null>(null);
    /** The grid grows with "Show more" instead of scrolling inside the dialog (3 per row → two rows). */
    protected readonly pageSize = 6;
    protected limit = signal(this.pageSize);
    protected list = computed(() => {
        const q = this.query().trim().toLowerCase();
        const c = this.category();
        return this.options().artworks.filter(
            (a) => (!q || `${a.title} ${a.artist ?? ''} ${a.code}`.toLowerCase().includes(q)) && (!c || a.categories.includes(c)),
        );
    });
    protected visible = computed(() => this.list().slice(0, this.limit()));
    /** Pinned above the grid, so the choice stays visible whatever the grid is filtered or paged to. */
    protected chosen = computed(() => {
        const id = this.selectedId();
        return id == null ? null : (this.options().artworks.find((a) => a.id === id) ?? null);
    });

    protected clearFilters(): void {
        this.query.set('');
        this.category.set('');
        this.limit.set(this.pageSize);
        this.search()?.nativeElement.focus();
    }

    /** "Change": bring the grid into view and put focus on the chosen tile (or the first one). */
    protected focusGrid(): void {
        const grid = this.grid()?.nativeElement;
        if (!grid) {
            this.search()?.nativeElement.focus();
            return;
        }
        grid.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        const tile = grid.querySelector<HTMLElement>('[data-tile][data-selected]') ?? grid.querySelector<HTMLElement>('[data-tile]');
        tile?.focus({ preventScroll: true });
    }
}

/* ------------------------------------------------------------------ */

@Component({
    selector: 'palette-input',
    imports: [Icon, UI],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="flex flex-wrap items-center gap-2">
            @for (c of value(); track $index) {
                <span class="relative">
                    <input type="color" class="size-9 cursor-pointer rounded border" [value]="c" (input)="setAt($index, $any($event.target).value)" />
                    <button type="button" class="bg-background absolute -top-1.5 -right-1.5 rounded-full border p-0.5" (click)="removeAt($index)">
                        <svg [icon]="X" class="size-3" />
                    </button>
                </span>
            }
            @if (value().length < 6) {
                <button ui-button type="button" variant="outline" size="sm" (click)="changed.emit([...value(), '#6B8E9F'])">Add colour</button>
                @if (!value().includes(wall())) {
                    <button ui-button type="button" variant="ghost" size="sm" (click)="changed.emit([...value(), wall()])">Match my wall</button>
                }
            }
        </div>
    `,
})
export class PaletteInput {
    readonly value = input.required<string[]>();
    readonly wall = input.required<string>();
    readonly changed = output<string[]>();
    protected readonly X = X;

    setAt(i: number, colour: string): void {
        this.changed.emit(this.value().map((x, j) => (j === i ? colour.toUpperCase() : x)));
    }

    removeAt(i: number): void {
        this.changed.emit(this.value().filter((_, j) => j !== i));
    }
}

/* ------------------------------------------------------------------ */

/** A size with the given long side and proportions (width ÷ height), rounded to whole centimetres. */
function fitAspect(longSideMm: number, ratio: number): { width_mm: number; height_mm: number } {
    const cm = (mm: number) => Math.max(10, Math.round(mm / 10) * 10);
    return ratio >= 1
        ? { width_mm: cm(longSideMm), height_mm: cm(longSideMm / ratio) }
        : { width_mm: cm(longSideMm * ratio), height_mm: cm(longSideMm) };
}

@Component({
    selector: 'size-fields',
    imports: [UI, ChoiceRow],
    changeDetection: ChangeDetectionStrategy.OnPush,
    template: `
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-4">
                <choice-row [value]="data().unit" [options]="options().enums.units" (changed)="form().set('unit', $any($event))" />
                <div class="text-muted-foreground text-xs">Sizes are saved exactly; switch units any time.</div>
            </div>

            <div>
                <label ui-label class="mb-1.5 block">{{ fixedSizes() ? 'Available sizes' : 'Popular sizes' }}</label>
                <div class="flex flex-wrap gap-2">
                    @for (s of presets(); track s.width_mm + 'x' + s.height_mm) {
                        <button
                            type="button"
                            [class]="
                                'rounded-md border px-2.5 py-1 text-xs transition ' +
                                (s.width_mm === data().width_mm && s.height_mm === data().height_mm
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted')
                            "
                            (click)="setSize(s.width_mm, s.height_mm)"
                        >
                            {{ data().unit === 'cm' && s.label ? s.label : label(s.width_mm, s.height_mm) }}
                        </button>
                    }
                </div>
            </div>

            @if (!fixedSizes()) {
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div class="grid gap-1.5">
                        <label ui-label>Width ({{ unitLabel() }})</label>
                        <input
                            ui-input
                            type="number"
                            min="1"
                            step="0.1"
                            [value]="w()"
                            (input)="w.set($any($event.target).value)"
                            (change)="setSide('width_mm', w())"
                            (blur)="setSide('width_mm', w())"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>Height ({{ unitLabel() }})</label>
                        <input
                            ui-input
                            type="number"
                            min="1"
                            step="0.1"
                            [value]="h()"
                            (input)="h.set($any($event.target).value)"
                            (change)="setSide('height_mm', h())"
                            (blur)="setSide('height_mm', h())"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <label ui-label>Quantity</label>
                        <input ui-input type="number" min="1" max="1000" [value]="data().quantity" (input)="setQuantity($any($event.target).value)" />
                    </div>
                    <div class="flex items-end">
                        @if (aspect()) {
                            <button
                                ui-button
                                type="button"
                                variant="outline"
                                size="sm"
                                [title]="'Swap width and height — the picture stays upright and stretches to fill'"
                                [attr.aria-pressed]="!!rotation()"
                                (click)="rotatePainting()"
                            >
                                ⟳ Rotate{{ rotation() ? ' · sides swapped' : '' }}
                            </button>
                        } @else {
                            <button ui-button type="button" variant="ghost" size="sm" (click)="setSize(data().height_mm, data().width_mm)">
                                ⟲ Rotate
                            </button>
                        }
                    </div>
                </div>
                @if (aspect()) {
                    <p class="text-muted-foreground -mt-2 text-xs">
                        This painting hangs {{ ratioLabel() }}{{ rotation() ? ' (sides swapped)' : '' }}: change the width or height and the other
                        side follows, so the whole picture is always shown, never cropped. Rotate swaps width and height; the picture stays upright and
                        stretches to fill the new size.
                    </p>
                }
            } @else {
                <div class="grid w-40 gap-1.5">
                    <label ui-label>Quantity</label>
                    <input ui-input type="number" min="1" max="1000" [value]="data().quantity" (input)="setQuantity($any($event.target).value)" />
                </div>
            }

            <div>
                <label ui-label class="mb-1.5 block">These measurements are…</label>
                <choice-row
                    [value]="data().size_reference"
                    [options]="options().enums.size_references"
                    (changed)="form().set('size_reference', $any($event))"
                />
                <p class="text-muted-foreground mt-1.5 text-xs">
                    “Artwork / image size” is the picture only. “Outer size” includes the mat and frame — choose it if you have a fixed space on the
                    wall.
                </p>
            </div>
        </div>
    `,
})
export class SizeFields {
    readonly form = input.required<InertiaForm<LineForm>>();
    readonly artwork = input<CatalogArtwork | null>(null);
    readonly options = input.required<CatalogOptions>();

    protected data = computed(() => this.form().data());
    protected fixedSizes = computed(() => !!this.artwork() && !this.artwork()!.allow_custom_size);
    /**
     * A catalogue painting keeps its own proportions (width ÷ height): sizes are scaled versions of it, never
     * cropped or stretched. Null for the customer's own artwork / new creations (free sizes).
     */
    /** 90 when width and height are swapped (stored as a quarter turn), else 0. */
    protected rotation = computed(() => (this.artwork() && ((this.data().image_rotation ?? 0) / 90) % 2 === 1 ? 90 : 0));
    /** The piece's proportions (width ÷ height) — of the painting as hung, i.e. after turning it. */
    protected aspect = computed(() => {
        const a = this.artwork();
        if (!a?.native_width_mm || !a.native_height_mm) return null;
        const ratio = a.native_width_mm / a.native_height_mm;
        return this.rotation() % 180 === 90 ? 1 / ratio : ratio;
    });

    /** Swap the piece's width and height (toggle); the upright picture is stretched to the new size. */
    rotatePainting(): void {
        this.form().update((d) => ({
            ...d,
            image_rotation: ((d.image_rotation ?? 0) / 90) % 2 === 1 ? 0 : 90,
            width_mm: d.height_mm,
            height_mm: d.width_mm,
        }));
    }
    protected presets = computed<{ width_mm: number; height_mm: number; label?: string }[]>(() => {
        const sizes = this.artwork()?.sizes ?? [];
        if (sizes.length) {
            // Catalogue sizes are of the painting unturned; the form holds the size as hung, so swap when it is on its side.
            if (this.rotation() % 180 !== 90) return sizes;
            return sizes.map((s) => ({ width_mm: s.height_mm, height_mm: s.width_mm }));
        }
        const ratio = this.aspect();
        if (!ratio) return this.options().standard_sizes;
        // Each popular size's long side, with the painting's own shape (deduplicated, smallest first).
        const seen = new Set<string>();
        return this.options()
            .standard_sizes.map((s) => fitAspect(Math.max(s.width_mm, s.height_mm), ratio))
            .filter((s) => {
                const key = `${s.width_mm}x${s.height_mm}`;
                return seen.has(key) ? false : (seen.add(key), true);
            })
            .sort((a, b) => a.width_mm * a.height_mm - b.width_mm * b.height_mm);
    });
    protected ratioLabel = computed(() => {
        const r = this.aspect();
        if (!r) return '';
        return Math.abs(r - 1) < 0.02 ? 'square' : r > 1 ? 'landscape' : 'portrait';
    });
    protected unitLabel = computed(() => (this.data().unit === 'cm' ? 'cm' : 'in'));
    protected w = signal('');
    protected h = signal('');

    constructor() {
        // Keep the text boxes in sync when size changes from elsewhere (preset, artwork, unit).
        effect(() => {
            const d = this.data();
            this.w.set(mmToUnit(d.width_mm, d.unit));
            this.h.set(mmToUnit(d.height_mm, d.unit));
        });
    }

    label(w: number, h: number): string {
        return sizeLabel(w, h, this.data().unit);
    }

    toMm(value: string): number {
        return unitToMm(value, this.data().unit as Unit);
    }

    setSize(width_mm: number, height_mm: number): void {
        this.form().update((d) => ({ ...d, width_mm, height_mm }));
    }

    /** Typed width or height: with a catalogue painting the other side follows its proportions. */
    setSide(side: 'width_mm' | 'height_mm', value: string): void {
        // Unchanged box (e.g. tabbing through, or blur after change): the shown value is rounded, so re-applying it would drift the size.
        if (Number(value) === Number(mmToUnit(this.data()[side], this.data().unit))) return;
        const mm = this.toMm(value);
        const ratio = this.aspect();
        if (!ratio || !(mm > 0)) {
            this.form().set(side, mm);
            return;
        }
        const width_mm = side === 'width_mm' ? mm : Math.round(mm * ratio);
        const height_mm = side === 'height_mm' ? mm : Math.round(mm / ratio);
        this.setSize(width_mm, height_mm);
    }

    setQuantity(value: string): void {
        this.form().set('quantity', Math.min(1000, Math.max(1, Math.round(Number(value)) || 1)));
    }
}
